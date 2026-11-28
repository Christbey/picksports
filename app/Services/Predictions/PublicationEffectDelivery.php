<?php

namespace App\Services\Predictions;

use App\Jobs\Predictions\DeliverPublicationEffect;
use App\Jobs\Predictions\GeneratePredictionNarrative;
use App\Jobs\Predictions\RefreshGameAnalysis;
use App\Models\CanonicalPrediction;
use App\Models\PredictionPublicationEffect;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Research\GameResearchService;
use App\Support\Sports\CanonicalEventStart;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PublicationEffectDelivery
{
    public const CACHE = 'cache';

    public const ANALYSIS = 'analysis';

    public const NARRATIVE = 'narrative';

    /** Called inside the publication transaction; original admission is frozen here. */
    public function record(CanonicalPrediction $prediction): void
    {
        $intents = [self::CACHE => []];
        if ($this->analysisEnabled($prediction->sport)) {
            $game = $prediction->sportEvent?->getRelationValue($prediction->sport.'Game');
            if ($game !== null) {
                $intents[self::ANALYSIS] = ['sport' => $prediction->sport, 'game_id' => (int) $game->getKey()];
            }
        }
        if ($prediction->sport !== 'nfl' && $prediction->calculationRun?->trigger !== 'coverage'
            && app(PredictionDeliverySource::class)->legacyWriterRetired($prediction->sport)) {
            $intents[self::NARRATIVE] = ['sport' => $prediction->sport];
        }
        foreach ($intents as $effect => $payload) {
            PredictionPublicationEffect::query()->firstOrCreate(
                ['canonical_prediction_id' => $prediction->getKey(), 'effect' => $effect],
                ['payload' => $payload, 'state' => 'pending', 'next_attempt_at' => now()],
            );
        }
    }

    /** Wake-up is best effort; the transactionally stored intents are authoritative. */
    public function wakeUp(int $predictionId): void
    {
        try {
            $ids = $this->dueQuery()->where('canonical_prediction_id', $predictionId)->limit(3)->pluck('id');
            foreach ($ids as $id) {
                try {
                    $this->dispatch((int) $id);
                } catch (Throwable $exception) {
                    $this->reportSafely($exception);
                }
            }
        } catch (Throwable $exception) {
            $this->reportSafely($exception);
        }
    }

    /** @return Builder<PredictionPublicationEffect> */
    public function dueQuery(): Builder
    {
        return PredictionPublicationEffect::query()->where(function (Builder $query): void {
            $query->where(fn (Builder $ready) => $ready->whereIn('state', ['pending', 'failed'])->where('attempts', '<', $this->maximumAttempts())
                ->where(fn (Builder $time) => $time->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
                ->orWhere(fn (Builder $expired) => $expired->whereIn('state', ['queued', 'running'])
                    ->where('lease_expires_at', '<=', now()));
        });
    }

    public function dispatch(int $effectId): bool
    {
        $token = DB::transaction(function () use ($effectId): ?string {
            $effect = $this->dueQuery()->whereKey($effectId)->lockForUpdate()->first();
            if ($effect === null) {
                return null;
            }
            if ($effect->attempts >= $this->maximumAttempts()) {
                $effect->update(['state' => 'exhausted', 'completed_at' => now(), 'lease_expires_at' => null,
                    'next_attempt_at' => null, 'reason_code' => 'attempt_limit_reached']);

                return null;
            }
            $token = (string) Str::uuid();
            $effect->update([
                'state' => 'queued', 'attempts' => $effect->attempts + 1,
                'owner_token' => $token, 'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'next_attempt_at' => null, 'reason_code' => null,
            ]);

            return $token;
        });
        if ($token === null) {
            return false;
        }
        try {
            if (PredictionPublicationEffect::query()->whereKey($effectId)->value('effect') === self::CACHE) {
                // Preserve immediate post-commit invalidation, with durable retry
                // when the cache is unavailable. No queue worker is needed here.
                $this->process($effectId, $token);
            } else {
                // No cache uniqueness lock can survive a failed queue handoff.
                // The wrapper fences its token before invoking existing handlers.
                Bus::dispatch(new DeliverPublicationEffect($effectId, $token));
            }
        } catch (Throwable $exception) {
            $this->fail($effectId, $token, $exception);
            $this->reportSafely($exception);

            return false;
        }

        return true;
    }

    public function process(int $effectId, string $token): void
    {
        $effect = DB::transaction(function () use ($effectId, $token): ?PredictionPublicationEffect {
            $reference = PredictionPublicationEffect::query()->find($effectId);
            if ($reference === null) {
                return null;
            }
            $eventId = CanonicalPrediction::query()->whereKey($reference->canonical_prediction_id)->value('sport_event_id');
            $event = SportEvent::query()->lockForUpdate()->findOrFail($eventId);
            $prediction = CanonicalPrediction::query()->lockForUpdate()->findOrFail($reference->canonical_prediction_id);
            $effect = PredictionPublicationEffect::query()->lockForUpdate()->findOrFail($effectId);
            if ($effect->state !== 'queued' || ! hash_equals((string) $effect->owner_token, $token)
                || $effect->lease_expires_at === null || $effect->lease_expires_at->lte(now())) {
                return null;
            }
            $reason = $this->cancellationReason($effect, $prediction, $event);
            if ($reason !== null) {
                $effect->update(['state' => 'cancelled', 'reason_code' => $reason, 'completed_at' => now(), 'lease_expires_at' => null]);

                return null;
            }
            $effect->update(['state' => 'running', 'lease_expires_at' => now()->addSeconds($this->leaseSeconds())]);

            return $effect;
        });
        if ($effect === null) {
            return;
        }
        try {
            match ($effect->effect) {
                self::CACHE => app(SportsViewCache::class)->bustSegments([
                    SportsViewCache::SEGMENT_DASHBOARD, SportsViewCache::SEGMENT_LIVE_SCOREBOARD,
                    SportsViewCache::SEGMENT_PREDICTIONS_INDEX, SportsViewCache::SEGMENT_PREDICTIONS_BY_GAME,
                    SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_DATES, SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_SEASONS,
                ]),
                self::ANALYSIS => app()->call([new RefreshGameAnalysis($effect->payload['sport'], $effect->payload['game_id']), 'handle']),
                self::NARRATIVE => app()->call([new GeneratePredictionNarrative(CanonicalPrediction::class, $effect->canonical_prediction_id, $effect->payload['sport']), 'handle']),
            };
            $this->finish($effectId, $token);
        } catch (Throwable $exception) {
            $this->fail($effectId, $token, $exception);
            $this->reportSafely($exception);
        }
    }

    private function cancellationReason(PredictionPublicationEffect $effect, CanonicalPrediction $prediction, SportEvent $event): ?string
    {
        if ($effect->effect === self::CACHE) {
            return null;
        }
        if (! in_array($effect->effect, [self::ANALYSIS, self::NARRATIVE], true)) {
            return 'unsupported_effect';
        }
        if ($prediction->publication_state !== 'published' || $prediction->phase !== 'pregame') {
            return 'forecast_not_selected';
        }
        if ($event->sport === 'mlb' && ! CanonicalEventStart::snapshot($event, $prediction->calculationRun?->inputSnapshot, true)) {
            return 'mlb_schedule_provenance_unavailable';
        }
        if ($effect->effect === self::NARRATIVE) {
            return app(PredictionDeliverySource::class)->legacyWriterRetired($prediction->sport) ? null : 'canonical_delivery_disabled';
        }
        if (! $this->analysisEnabled($prediction->sport)) {
            return 'analysis_disabled';
        }
        $start = ($event->sport === 'cfb' ? CanonicalEventStart::forCurrentCfbEvent($event, true) : CanonicalEventStart::forEvent($event, true));
        if ($start === null || $start->lte(now())) {
            return 'pregame_deadline_passed';
        }
        $model = app(SportContextResolver::class)->resolve($prediction->sport)->models['game'];
        $game = $model::query()->whereKey($effect->payload['game_id'])->where('sport_event_id', $event->id)->lockForUpdate()->first();
        if ($game === null || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            return 'game_not_admitted';
        }

        return null;
    }

    private function finish(int $effectId, string $token): void
    {
        DB::transaction(function () use ($effectId, $token): void {
            $effect = $this->owned($effectId, $token);
            if ($effect !== null) {
                $effect->update(['state' => 'completed', 'completed_at' => now(), 'lease_expires_at' => null, 'reason_code' => null]);
            }
        });
    }

    private function fail(int $effectId, string $token, Throwable $exception): void
    {
        DB::transaction(function () use ($effectId, $token, $exception): void {
            $effect = $this->owned($effectId, $token, ['queued', 'running']);
            if ($effect !== null) {
                $delay = min(3600, max(1, (int) config('prediction_lifecycle.publication_effects.retry_seconds', 60)) * (2 ** min(6, $effect->attempts - 1)));
                $exhausted = $effect->attempts >= $this->maximumAttempts();
                $effect->update(['state' => $exhausted ? 'exhausted' : 'failed', 'lease_expires_at' => null,
                    'next_attempt_at' => $exhausted ? null : now()->addSeconds($delay),
                    'completed_at' => $exhausted ? now() : null, 'reason_code' => class_basename($exception)]);
            }
        });
    }

    private function owned(int $effectId, string $token, array $states = ['running']): ?PredictionPublicationEffect
    {
        $effect = PredictionPublicationEffect::query()->lockForUpdate()->find($effectId);

        return $effect !== null && in_array($effect->state, $states, true) && hash_equals((string) $effect->owner_token, $token)
            && $effect->lease_expires_at?->isFuture() ? $effect : null;
    }

    private function analysisEnabled(string $sport): bool
    {
        return (bool) config('game_analysis.publisher_enabled', false)
            && (app(GameResearchService::class)->enabled($sport) || app(PredictionReviewCoordinator::class)->enabled($sport));
    }

    private function maximumAttempts(): int
    {
        return min(20, max(1, (int) config('prediction_lifecycle.publication_effects.max_attempts', 8)));
    }

    private function leaseSeconds(): int
    {
        return min(3600, max(180, (int) config('prediction_lifecycle.publication_effects.lease_seconds', 300)));
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Infrastructure reporting failure cannot roll back a committed
            // publication; its durable incomplete intent remains inspectable.
        }
    }
}
