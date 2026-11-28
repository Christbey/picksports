<?php

namespace Tests\Support;

use App\Models\MLB\Game;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\MLB\MlbGameStart;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/** Explicit synthetic producer/capture fixture; never qualifies ordinary factories or reads. */
final class ObservedMlbGameFixture
{
    public static function observe(Game $game, CarbonInterface $startsAt, bool $syncEvent = true): Game
    {
        $start = CarbonImmutable::instance($startsAt)->utc()->startOfSecond();
        // With no synthetic venue timezone, the real adapter's fallback split fields are UTC.
        $attributes = ['game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
            ...MlbGameStart::observed($start->toIso8601String(), [])];
        $game->forceFill(MlbGameStart::prepare($game, $attributes))->save();
        $game->refresh();
        if ($syncEvent) {
            app(SportEventIdentitySynchronizer::class)->sync('mlb', $game);
        }

        return $game->refresh();
    }

    /** Clock metadata for an explicitly observed synthetic input; does not attest model/provider quality. */
    public static function snapshotAttributes(Game $game, array $inputs = [], ?CarbonInterface $capturedAt = null): array
    {
        $capture = CarbonImmutable::instance($capturedAt ?? now())->utc();
        $start = MlbGameStart::for($game);
        $carrier = LegacyTimestampCarrier::forInstant($capture);
        $cutoff = MlbGameStart::canonical($game);
        if ($start === null || $carrier === null || $cutoff === null || $game->sport_event_id === null
            || ! MlbGameStart::observedBy($game, $capture) || ! $capture->lt($start)) {
            throw new LogicException('Explicit observed pregame fixture is required before creating a qualified MLB input.');
        }
        $inputs['event'] = [...($inputs['event'] ?? []), 'game_id' => (int) $game->id];

        return ['sport' => 'mlb', 'sport_event_id' => $game->sport_event_id, 'phase' => 'pregame',
            'captured_at' => $carrier, 'cutoff_at' => $cutoff, 'latest_source_available_at' => $carrier,
            'pregame_safety_status' => 'verified', 'inputs' => $inputs,
            'content_hash' => app(CanonicalPayloadHasher::class)->hash($inputs),
            'metadata' => ['mlb_timing_policy' => MlbGameStart::TIMING_POLICY,
                'mlb_start_observation' => MlbGameStart::capture($game, $capture)]];
    }
}
