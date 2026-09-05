<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SportEventResultRecorder
{
    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    /** @param array<string, mixed> $metadata */
    public function record(
        SportEvent $event,
        int $homeScore,
        int $awayScore,
        string $source,
        ?string $sourceReference = null,
        ?CarbonImmutable $observedAt = null,
        ?CarbonImmutable $finalizedAt = null,
        array $metadata = [],
    ): SportEventResult {
        if (! $event->exists) {
            throw new PredictionLifecycleException('Event result recording requires a persisted canonical event.');
        }

        if ($homeScore < 0 || $awayScore < 0) {
            throw new PredictionLifecycleException('Event result scores cannot be negative.');
        }

        $source = trim($source);

        if ($source === '') {
            throw new PredictionLifecycleException('Event result source is required.');
        }

        $resultHash = $this->hasher->hash([
            'event' => $event->public_id,
            'status' => 'official',
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ]);

        return DB::transaction(function () use (
            $event,
            $homeScore,
            $awayScore,
            $source,
            $sourceReference,
            $observedAt,
            $finalizedAt,
            $metadata,
            $resultHash,
        ): SportEventResult {
            SportEvent::query()->lockForUpdate()->findOrFail($event->getKey());

            $existing = SportEventResult::query()->where('result_hash', $resultHash)->first();

            if ($existing !== null) {
                if ($existing->sport_event_id !== $event->getKey()) {
                    throw new PredictionLifecycleException('Event result hash is associated with another event.');
                }

                return $existing;
            }

            $latest = SportEventResult::query()
                ->where('sport_event_id', $event->getKey())
                ->orderByDesc('revision')
                ->first();

            return SportEventResult::query()->create([
                'sport_event_id' => $event->getKey(),
                'revision' => ($latest?->revision ?? 0) + 1,
                'supersedes_sport_event_result_id' => $latest?->getKey(),
                'status' => 'official',
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'source' => $source,
                'source_reference' => $sourceReference === null ? null : trim($sourceReference),
                'result_hash' => $resultHash,
                'observed_at' => $observedAt ?? CarbonImmutable::now(),
                'finalized_at' => $finalizedAt ?? $observedAt ?? CarbonImmutable::now(),
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        });
    }
}
