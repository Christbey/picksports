<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\SportEvent;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use Illuminate\Database\Eloquent\Model;

class EstablishedModelCutoverReadiness
{
    public function __construct(
        private readonly CanonicalSportCutoverReadinessService $readiness,
        private readonly CalculationReleaseSelector $releases,
        private readonly CanonicalSportPredictionQuery $predictions,
    ) {}

    /**
     * @param  class-string<Model>  $gameModel
     * @param  \Closure(Model): bool|null  $eligible
     * @param  \Closure(Model): bool|null  $identityMatches
     * @return array<string, mixed>
     */
    public function report(string $sport, string $gameModel, string $calculatorName, string $legacySchema, string $fullSchema, string $forecastSource, ?int $season = null, ?\Closure $eligible = null, ?\Closure $identityMatches = null): array
    {
        $start = now()->toImmutable();
        $end = $start->addDays(8);
        $release = null;
        $blockers = [];
        try {
            $release = $this->releases->select(new SportEvent(['sport' => $sport]), 'pregame');
            if ($release->calculator_name !== $calculatorName
                || ! in_array($release->input_schema_version, [$legacySchema, $fullSchema], true)
                || (data_get($release->configuration, 'forecast.source') === $forecastSource && $release->input_schema_version !== $fullSchema)) {
                $blockers[] = 'canonical_release_incompatible';
            }
        } catch (PredictionLifecycleException) {
            $blockers[] = 'canonical_release_unavailable';
        }
        $games = $gameModel::whereNotNull('sport_event_id')->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->when($season !== null, fn ($q) => $q->where('season', $season))
            ->whereHas('sportEvent', fn ($q) => $q->whereBetween('starts_at', [$start, $end]))
            ->when($eligible !== null || $identityMatches !== null, fn ($q) => $q->with(['homeTeam', 'awayTeam', 'sportEvent:id,starts_at,neutral_site']))
            ->get(['id', 'sport_event_id', 'home_team_id', 'away_team_id', 'game_date', 'game_time', ...($sport === 'mlb' ? [] : ['neutral_site'])]);
        $scheduledCount = $games->count();
        $held = $eligible === null ? collect() : $games->reject($eligible);
        $games = $eligible === null ? $games : $games->filter($eligible);
        $identityHolds = $identityMatches === null ? [] : $games->reject($identityMatches)->pluck('id')->values()->all();
        if ($identityHolds !== []) {
            $blockers[] = 'canonical_game_identity_mismatch';
        }
        $report = $this->readiness->report($sport, $season, releaseVersion: $release?->semantic_version,
            horizonStart: $start, horizonEnd: $end, excludedEventIds: $held->pluck('sport_event_id')->all());

        $unlinked = $gameModel::whereNull('sport_event_id')->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->when($season !== null, fn ($q) => $q->where('season', $season))
            ->whereDate('game_date', '>=', $start->toDateString())->whereDate('game_date', '<=', $end->toDateString())
            ->pluck('id')->all();
        if ($unlinked !== []) {
            $blockers[] = 'unlinked_slate_games';
        }
        $fullModelEvents = $this->predictions->queryForSport($sport, array_filter(['season' => $season]))
            ->whereIn('predictions.sport_event_id', $games->pluck('sport_event_id'))
            ->where('predictions.output_metadata->forecast_source', $forecastSource)
            ->when($release !== null, fn ($q) => $q->whereHas('calculationRun', fn ($q) => $q->where('calculation_release_id', $release->id)))
            ->select('predictions.sport_event_id')->pluck('predictions.sport_event_id');
        if ($games->isEmpty()) {
            $blockers[] = 'no_upcoming_slate';
        }
        if (data_get($release?->configuration, 'forecast.source') !== $forecastSource) {
            $blockers[] = 'established_model_release_required';
        }
        $missing = $games->whereNotIn('sport_event_id', $fullModelEvents)->pluck('id')->values()->all();
        if ($missing !== []) {
            $blockers[] = 'missing_full_model_forecasts';
        }
        if (! $report['ready_for_cutover']) {
            $blockers[] = 'canonical_coverage_not_ready';
        }
        $inflight = $gameModel::whereNotNull('sport_event_id')->whereHas('prediction')
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'STATUS_IN_PROGRESS', 'STATUS_END_PERIOD', 'STATUS_HALFTIME', 'STATUS_SUSPENDED'])
            ->when($season !== null, fn ($q) => $q->where('season', $season))
            ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '<=', $start))
            ->get(['id', 'sport_event_id']);
        $nativeInflight = $this->predictions->queryForSport($sport, array_filter(['season' => $season]))
            ->whereIn('predictions.sport_event_id', $inflight->pluck('sport_event_id'))
            ->select('predictions.sport_event_id')->pluck('predictions.sport_event_id');
        $inflightHolds = $inflight->whereNotIn('sport_event_id', $nativeInflight)->pluck('id')->values()->all();
        if ($inflightHolds !== []) {
            $blockers[] = 'inflight_legacy_baselines_require_compatibility';
        }
        $transportReady = $blockers === [];
        if (! config("prediction_lifecycle.canonical_pipeline.{$sport}")) {
            $blockers[] = 'canonical_pipeline_disabled';
        }

        return [...$report, ...($eligible === null ? [] : ['scheduled_slate_event_count' => $scheduledCount, 'held_placeholder_game_ids' => $held->pluck('id')->values()->all(), 'identity_mismatch_game_ids' => $identityHolds]), 'identity_mismatch_game_ids' => $identityHolds, 'selected_release_id' => $release?->id,
            'inflight_legacy_game_ids' => $inflightHolds, 'unlinked_slate_game_ids' => $unlinked, 'slate_event_count' => $games->count(), 'full_model_forecast_event_count' => $fullModelEvents->count(),
            'missing_full_model_forecast_game_ids' => $missing, 'readiness_blockers' => $blockers,
            'read_transport_ready' => $transportReady, 'model_performance_review_required' => true,
            'ready_for_cutover' => $blockers === [],
            'next_action' => $blockers === [] ? 'Verify exact established-model parity, historical display, and approval holds before enabling canonical reads.'
                : 'Resolve release and upcoming-slate coverage blockers; preserve historical records and approval holds. Do not backfill completed games as native pregame forecasts.',
        ];
    }
}
