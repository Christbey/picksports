<?php

namespace App\Services\Predictions\Review;

use App\Models\AiGeneration;
use App\Models\AnalysisWorkItem;
use App\Services\AI\AiGenerationRecorder;
use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class ReviewSpendAdmission
{
    private const MAX_USAGE_ROWS = 10000;

    private const MAX_METADATA_BYTES = 32768;

    private const MAX_TOTAL_METADATA_BYTES = 4194304;

    private const MAX_METADATA_TOKENS = 2000;

    private const MAX_TOTAL_METADATA_TOKENS = 15000;

    public function __construct(private readonly AiGenerationRecorder $recorder, private readonly AiProviderRateLimitCircuitBreaker $circuit) {}

    public function reserve(AnalysisWorkItem $item): AiGeneration
    {
        $spec = $item->execution_spec;
        $cooldown = $this->circuit->retryAfterSeconds($spec['provider']);
        if ($cooldown > 0) {
            throw new ReviewDeferred('provider_cooldown', $cooldown);
        }

        try {
            return Cache::lock('prediction-review:spend-admission', 15)->block(3, function () use ($item, $spec): AiGeneration {
                $rows = $this->usageRows();
                $reservation = $this->amount(config('prediction_review.budget.reservation_usd', 0.05));
                if ($reservation->isLessThan('0.001000')) {
                    $reservation = BigDecimal::of('0.001000');
                }
                $cost = function (AiGeneration $row) use ($reservation): BigDecimal {
                    if ($row->getRawOriginal('cost_usd') !== null) {
                        return $this->amount($row->getRawOriginal('cost_usd'));
                    }
                    $reserved = $this->amount(data_get($row->metadata, 'reserved_cost_usd') ?? 0);

                    return $reserved->isGreaterThan($reservation) ? $reserved : $reservation;
                };
                $sport = $rows->filter(fn ($row) => data_get($row->metadata, 'sport') === $item->sport
                    || ($item->sport === 'nfl' && $row->context_type === 'nfl_game'));
                $event = $sport->filter(fn ($row) => data_get($row->metadata, 'game_table') === $item->game_table
                    && (int) data_get($row->metadata, 'game_id') === (int) $item->game_id
                    || ($item->sport === 'nfl' && $row->context_type === 'nfl_game' && (int) $row->context_id === (int) $item->game_id));
                $uncertainWindow = max(60, (int) config('prediction_review.unknown_usage_retry_seconds', 3600));
                if ($event->contains(fn ($row) => data_get($row->metadata, 'desired_fingerprint') === $item->desired_fingerprint
                    && ($row->status === 'running' || data_get($row->metadata, 'usage_cost_status') === 'unknown_reserved')
                    && $row->started_at?->gt(now()->subSeconds($uncertainWindow)))) {
                    throw new ReviewDeferred('previous_usage_unknown', $uncertainWindow);
                }
                foreach ([['application', $rows], ['sport', $sport], ['event', $event]] as [$scope, $selected]) {
                    $total = BigDecimal::of('0.000000');
                    foreach ($selected as $row) {
                        $total = $total->plus($cost($row));
                    }
                    if ($total->plus($reservation)->isGreaterThan($this->amount(config('prediction_review.budget.'.$scope.'_daily_usd', 0)))) {
                        throw new ReviewDeferred($scope.'_budget_reached', 3600);
                    }
                }
                $attemptLimit = max(1, (int) config('prediction_review.budget.event_daily_attempts', 3));
                if ($item->deadline_at?->gt(now()->addHours(max(1, (int) config('prediction_review.budget.pregame_reserve_hours', 12))))) {
                    $attemptLimit = max(0, $attemptLimit - max(0, (int) config('prediction_review.budget.pregame_reserved_attempts', 1)));
                }
                if ($event->count() >= $attemptLimit) {
                    throw new ReviewDeferred('event_attempt_limit_reached', 3600);
                }

                return $this->recorder->start(
                    purpose: 'prediction_review', promptVersion: $spec['prompt_version'], provider: $spec['provider'], model: $spec['model'],
                    input: ['input' => $item->input_payload, 'evidence' => $item->evidence], contextType: 'analysis_work_item', contextId: (string) $item->id,
                    metadata: ['reserved_cost_usd' => $reservation->toFloat(), 'sport' => $item->sport, 'game_table' => $item->game_table,
                        'game_id' => $item->game_id, 'desired_fingerprint' => $item->desired_fingerprint],
                );
            });
        } catch (LockTimeoutException) {
            throw new ReviewDeferred('budget_admission_busy', 30);
        }
    }

    /** @return Collection<int, AiGeneration> */
    private function usageRows(): Collection
    {
        $query = AiGeneration::query()->where('started_at', '>=', now()->subDay());
        [$sizeExpression, $costSizeExpression] = match ($query->getConnection()->getDriverName()) {
            'mysql' => ['COALESCE(OCTET_LENGTH(metadata), 0)', 'COALESCE(OCTET_LENGTH(cost_usd), 0)'],
            'sqlite' => ['COALESCE(length(CAST(metadata AS BLOB)), 0)', 'COALESCE(length(CAST(cost_usd AS BLOB)), 0)'],
            default => throw new ReviewDeferred('usage_storage_unsupported', 3600),
        };
        $preflight = (clone $query)->select(['id'])
            ->selectRaw($costSizeExpression.' as cost_bytes')
            ->selectRaw('CASE WHEN '.$costSizeExpression.' <= 64 THEN cost_usd ELSE NULL END as cost_usd')
            ->selectRaw($sizeExpression.' as metadata_bytes')->orderBy('id')
            ->limit(self::MAX_USAGE_ROWS + 1)->toBase()->get();
        if ($preflight->count() > self::MAX_USAGE_ROWS) {
            throw new ReviewDeferred('usage_inventory_limit', 3600);
        }
        $totalBytes = 0;
        foreach ($preflight as $row) {
            $bytes = (int) $row->metadata_bytes;
            $totalBytes += $bytes;
            if ($bytes > self::MAX_METADATA_BYTES || $totalBytes > self::MAX_TOTAL_METADATA_BYTES) {
                throw new ReviewDeferred('usage_inventory_limit', 3600);
            }
            if ((int) $row->cost_bytes > 64) {
                throw new ReviewDeferred('invalid_usage_telemetry', 3600);
            }
            if ($row->cost_usd !== null) {
                $this->amount($row->cost_usd);
            }
        }
        if ($preflight->isEmpty()) {
            return new Collection;
        }
        $rows = new Collection;
        $totalBytes = 0;
        $totalTokens = 0;
        foreach ($preflight->chunk(100) as $selected) {
            // Recheck scalar limits before transferring bodies; a growing row
            // cannot defeat preflight, and one bounded batch caps overshoot.
            $batch = (clone $query)->whereKey($selected->pluck('id')->all())
                ->whereRaw($sizeExpression.' <= ?', [self::MAX_METADATA_BYTES])
                ->whereRaw($costSizeExpression.' <= 64')->get();
            if ($batch->count() !== $selected->count()) {
                throw new ReviewDeferred('usage_inventory_changed', 3600);
            }
            foreach ($batch as $row) {
                $raw = $row->getRawOriginal('metadata');
                $bytes = $raw === null ? 0 : strlen((string) $raw);
                $totalBytes += $bytes;
                if ($bytes > self::MAX_METADATA_BYTES || $totalBytes > self::MAX_TOTAL_METADATA_BYTES) {
                    throw new ReviewDeferred('usage_inventory_limit', 3600);
                }
                $totalTokens += $this->metadataTokens($raw ?? '');
                if ($totalTokens > self::MAX_TOTAL_METADATA_TOKENS) {
                    throw new ReviewDeferred('usage_inventory_limit', 3600);
                }
                try {
                    $metadata = $raw === null ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($metadata)) {
                        throw new \UnexpectedValueException;
                    }
                } catch (\Throwable) {
                    throw new ReviewDeferred('invalid_usage_telemetry', 3600);
                }
                if (data_get($metadata, 'reserved_cost_usd') !== null) {
                    $this->amount($metadata['reserved_cost_usd']);
                }
                $rows->push($row);
            }
        }

        return $rows;
    }

    private function metadataTokens(string $json): int
    {
        $tokens = 0;
        $quoted = $escaped = $literal = false;
        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $character = $json[$i];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }

                continue;
            }
            if ($character === '"') {
                $quoted = true;
                $literal = false;
                $tokens++;
            } elseif ($character === '[' || $character === '{') {
                $tokens++;
                $literal = false;
            } elseif (str_contains(',:]}', $character)) {
                $literal = false;
            } elseif (! ctype_space($character) && ! $literal) {
                $tokens++;
                $literal = true;
            }
            if ($tokens > self::MAX_METADATA_TOKENS) {
                throw new ReviewDeferred('usage_inventory_limit', 3600);
            }
        }

        return $tokens;
    }

    private function amount(mixed $value): BigDecimal
    {
        try {
            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new \UnexpectedValueException;
            }
            // An exponent string can expand far beyond its input byte length
            // inside Brick Math, before a numeric range check can reject it.
            if (is_string($value) && (strlen($value) > 64 || preg_match('/\A[0-9]+(?:\.[0-9]{1,6})?\z/D', $value) !== 1)) {
                throw new \UnexpectedValueException;
            }
            if (is_float($value) && ! is_finite($value)) {
                throw new \UnexpectedValueException;
            }
            $amount = BigDecimal::of($value);
            if ($amount->isNegative() || $amount->isGreaterThan('999999.999999')) {
                throw new \UnexpectedValueException;
            }

            return $amount->toScale(6);
        } catch (\Throwable) {
            throw new ReviewDeferred('invalid_usage_telemetry', 3600);
        }
    }
}
