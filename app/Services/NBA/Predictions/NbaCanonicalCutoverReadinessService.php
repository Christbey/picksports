<?php

namespace App\Services\NBA\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\NBA\Game;
use App\Models\SportEvent;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CalculationReleaseSelector;
use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class NbaCanonicalCutoverReadinessService
{
    public function __construct(
        private readonly CanonicalSportCutoverReadinessService $readiness,
        private readonly CalculationReleaseSelector $releases,
        private readonly CanonicalSportPredictionQuery $predictions,
    ) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        $start = now()->toImmutable();
        $end = $start->addDays(8);
        $release = null;
        $blockers = [];
        try {
            $release = $this->releases->select(new SportEvent(['sport' => 'nba']), 'pregame');
            if ($release->calculator_name !== NbaCalculationReleaseDefinition::CALCULATOR_NAME
                || ! in_array($release->input_schema_version, [NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION, NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION], true)
                || (data_get($release->configuration, 'forecast.source') === NbaFullModelReleaseDefinition::FORECAST_SOURCE && $release->input_schema_version !== NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION)) {
                $blockers[] = 'canonical_release_incompatible';
            }
        } catch (PredictionLifecycleException) {
            $blockers[] = 'canonical_release_unavailable';
        }
        $report = $this->readiness->report('nba', $season, releaseVersion: $release?->semantic_version,
            horizonStart: $start, horizonEnd: $end);
        $games = Game::whereNotNull('sport_event_id')->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->when($season !== null, fn ($q) => $q->where('season', $season))
            ->whereHas('sportEvent', fn ($q) => $q->whereBetween('starts_at', [$start, $end]))
            ->get(['id', 'sport_event_id']);
        $unlinked = Game::whereNull('sport_event_id')->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->when($season !== null, fn ($q) => $q->where('season', $season))
            ->whereDate('game_date', '>=', $start->toDateString())->whereDate('game_date', '<=', $end->toDateString())
            ->pluck('id')->all();
        if ($unlinked !== []) {
            $blockers[] = 'unlinked_slate_games';
        }
        $fullModelEvents = $this->predictions->queryForSport('nba', array_filter(['season' => $season]))
            ->whereIn('predictions.sport_event_id', $games->pluck('sport_event_id'))
            ->where('predictions.output_metadata->forecast_source', NbaFullModelReleaseDefinition::FORECAST_SOURCE)
            ->when($release !== null, fn ($q) => $q->whereHas('calculationRun', fn ($q) => $q->where('calculation_release_id', $release->id)))
            ->pluck('predictions.sport_event_id');
        if ($games->isEmpty()) {
            $blockers[] = 'no_upcoming_slate';
        }
        if (data_get($release?->configuration, 'forecast.source') !== NbaFullModelReleaseDefinition::FORECAST_SOURCE) {
            $blockers[] = 'established_model_release_required';
        }
        $missing = $games->whereNotIn('sport_event_id', $fullModelEvents)->pluck('id')->values()->all();
        if ($missing !== []) {
            $blockers[] = 'missing_full_model_forecasts';
        }
        if (! $report['ready_for_cutover']) {
            $blockers[] = 'canonical_coverage_not_ready';
        }
        $transportReady = $blockers === [];
        if (! config('prediction_lifecycle.canonical_pipeline.nba')) {
            $blockers[] = 'canonical_pipeline_disabled';
        }

        return [...$report, 'selected_release_id' => $release?->id,
            'unlinked_slate_game_ids' => $unlinked, 'slate_event_count' => $games->count(), 'full_model_forecast_event_count' => $fullModelEvents->count(),
            'missing_full_model_forecast_game_ids' => $missing, 'readiness_blockers' => $blockers,
            'read_transport_ready' => $transportReady, 'model_performance_review_required' => true,
            'ready_for_cutover' => $blockers === [],
            'next_action' => $blockers === [] ? 'Verify exact established-model parity, historical display, and approval holds before enabling NBA reads.'
                : 'Resolve release and upcoming-slate coverage blockers; preserve historical records and approval holds. Do not backfill completed games as native pregame forecasts.',
        ];
    }
}
