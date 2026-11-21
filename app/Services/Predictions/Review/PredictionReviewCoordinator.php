<?php

namespace App\Services\Predictions\Review;

use App\Models\AnalysisWorkItem;
use App\Services\Predictions\PredictionReviewInputBuilder;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PredictionReviewCoordinator
{
    public function __construct(private readonly PredictionReviewInputBuilder $inputs, private readonly ReviewFingerprint $fingerprints) {}

    public function enabled(string $sport): bool
    {
        return (bool) config('prediction_review.enabled', false)
            && (bool) config('prediction_review.sports.'.$sport, false);
    }

    public function reconcileGame(Model $game, string $sport, array $evidence = []): ?AnalysisWorkItem
    {
        if (! $this->enabled($sport) || ! Schema::hasTable('analysis_work_items')) {
            return null;
        }
        $input = $this->inputs->forGame($game, $sport);
        $key = hash('sha256', $sport.'|'.$game->getTable().'|'.$game->getKey().'|prediction_review');
        $deadline = data_get($input?->identity, 'starts_at') !== null
            ? CarbonImmutable::parse($input->identity['starts_at'])
            : app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        // SQL timestamps follow the application timezone, while input identities use UTC.
        $deadline = $deadline?->setTimezone((string) config('app.timezone', 'UTC'));
        if ($input === null || ! $deadline?->isFuture() || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            AnalysisWorkItem::where('work_key', $key)->update(['state' => 'cancelled', 'reason_code' => 'forecast_or_pregame_unavailable', 'lease_token' => null, 'lease_expires_at' => null]);

            return null;
        }
        $hashes = $this->fingerprints->forInput($input, $evidence);

        return DB::transaction(function () use ($game, $sport, $input, $key, $deadline, $evidence, $hashes): AnalysisWorkItem {
            $item = AnalysisWorkItem::firstOrCreate(['work_key' => $key], [
                'sport' => $sport, 'sport_event_id' => $game->sport_event_id, 'game_table' => $game->getTable(), 'game_id' => $game->id,
                'stage' => 'prediction_review', 'desired_fingerprint' => $hashes['fingerprint'], 'input_payload' => $input->toArray(),
                'execution_spec' => $this->fingerprints->executionSpec(), 'evidence' => $evidence,
                'state' => 'pending', 'deadline_at' => $deadline, 'next_attempt_at' => now(),
            ]);
            $item = AnalysisWorkItem::lockForUpdate()->findOrFail($item->id);
            $changed = $item->desired_fingerprint !== $hashes['fingerprint'];
            $expired = $item->state === 'succeeded' && ! $item->revisions()->where('desired_fingerprint', $item->desired_fingerprint)->where('expires_at', '>', now())->exists();
            if ($changed || $expired || $item->state === 'cancelled') {
                $item->update(['desired_fingerprint' => $hashes['fingerprint'], 'input_payload' => $input->toArray(),
                    'execution_spec' => $this->fingerprints->executionSpec(), 'evidence' => $evidence,
                    'state' => 'pending', 'reason_code' => null, 'attempts' => 0, 'deadline_at' => $deadline,
                    'next_attempt_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
            } elseif ($item->deadline_at?->timestamp !== $deadline->timestamp) {
                // Repair earlier serialized deadlines without resetting attempts, leases or paid history.
                $item->update(['deadline_at' => $deadline]);
            }

            return $item->refresh();
        });
    }

    /** Authorized operational callers only; admission budgets and circuits remain intact. */
    public function retryGame(Model $game, string $sport, array $evidence = []): ?AnalysisWorkItem
    {
        $item = $this->reconcileGame($game, $sport, $evidence);
        if ($item === null) {
            return null;
        }

        return DB::transaction(function () use ($item): AnalysisWorkItem {
            $row = AnalysisWorkItem::lockForUpdate()->findOrFail($item->id);
            if (in_array($row->state, ['failed', 'deferred'], true)) {
                $row->update(['state' => 'pending', 'reason_code' => null, 'attempts' => 0,
                    'next_attempt_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
            }

            return $row->refresh();
        });
    }
}
