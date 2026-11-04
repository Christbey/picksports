<?php

namespace App\Services\WNBA\Predictions;

use App\Models\WNBA\Game;
use App\Services\Predictions\EstablishedModelCutoverReadiness;
use App\Services\Sports\SportsDateWindowService;

class WnbaCanonicalCutoverReadinessService
{
    public function __construct(private readonly EstablishedModelCutoverReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null, bool $offseason = false): array
    {
        $report = $this->readiness->report(
            sport: 'wnba', gameModel: Game::class,
            calculatorName: WnbaCalculationReleaseDefinition::CALCULATOR_NAME,
            legacySchema: WnbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            fullSchema: WnbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            forecastSource: WnbaFullModelReleaseDefinition::FORECAST_SOURCE,
            season: $season,
            identityMatches: fn ($game) => $this->identityMatches($game),
        );
        $active = Game::whereNotIn('status', ['STATUS_FINAL', 'STATUS_CANCELED', 'STATUS_POSTPONED', 'STATUS_UNRESOLVED'])
            ->when(! $offseason && $season !== null, fn ($query) => $query->where('season', $season));
        $elapsed = (clone $active)->whereDate('game_date', '<', today())->pluck('id')->all();
        $blockers = $report['readiness_blockers'];
        if ($elapsed !== []) {
            $blockers[] = 'stale_active_game_records';
        }
        if ($report['historical_unsafe_published_revision_count'] > 0 || $report['historical_duplicate_published_event_count'] > 0
            || $report['historical_missing_evaluation_count'] > 0) {
            $blockers[] = 'canonical_history_not_ready';
        }
        if ($offseason) {
            if ($active->exists()) {
                $blockers[] = 'offseason_requires_no_active_games';
            } elseif ($report['unsafe_published_revision_count'] === 0 && $report['duplicate_published_event_count'] === 0) {
                $blockers = array_values(array_diff($blockers, ['no_upcoming_slate', 'canonical_coverage_not_ready']));
            }
        }
        $report['stale_active_game_ids'] = $elapsed;
        $report['held_unresolved_game_ids'] = Game::where('status', 'STATUS_UNRESOLVED')->when($season !== null, fn ($q) => $q->where('season', $season))->pluck('id')->all();
        $report['readiness_mode'] = $offseason ? 'offseason' : 'slate';
        $report['readiness_blockers'] = array_values(array_unique($blockers));
        $report['read_transport_ready'] = array_diff($blockers, ['canonical_pipeline_disabled']) === [];
        $report['ready_for_cutover'] = $blockers === [];
        $report['next_action'] = $offseason && $blockers === []
            ? 'Verify stored model transport parity, historical display, settlement and approval holds before enabling reads; validate the next real slate prospectively.'
            : $report['next_action'];

        return $report;
    }

    public function identityMatches(Game $game): bool
    {
        $tip = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        $event = $game->sportEvent;

        return $event !== null && $event->sport === 'wnba' && $tip !== null && $event->starts_at !== null
            && $tip->equalTo($event->starts_at);
    }
}
