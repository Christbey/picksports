<?php

namespace App\Services\MLB\Predictions;

use App\Models\MLB\Game;
use App\Services\Predictions\EstablishedModelCutoverReadiness;

class MlbCanonicalCutoverReadinessService
{
    public function __construct(private readonly EstablishedModelCutoverReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null, bool $offseason = false): array
    {
        $report = $this->readiness->report(
            sport: 'mlb', gameModel: Game::class,
            calculatorName: MlbCalculationReleaseDefinition::CALCULATOR_NAME,
            legacySchema: MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            fullSchema: MlbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            forecastSource: MlbFullModelReleaseDefinition::FORECAST_SOURCE,
            season: $season,
            identityMatches: fn ($game) => app(MlbPregameIdentity::class)->matches($game),
        );
        $active = Game::whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'STATUS_IN_PROGRESS', 'STATUS_END_PERIOD', 'STATUS_SUSPENDED'])
            ->when($season !== null, fn ($query) => $query->where('season', $season));
        $elapsed = (clone $active)->whereDate('game_date', '<', today())->pluck('id')->all();
        $blockers = $report['readiness_blockers'];
        if ($elapsed !== []) {
            $blockers[] = 'stale_active_game_records';
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
}
