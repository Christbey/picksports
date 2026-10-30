<?php

namespace App\Services\NFL;

use App\Models\CommandHeartbeat;
use App\Models\NFL\Game;
use App\Models\NflSignalGrade;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Support\NflModelVersion;
use Carbon\CarbonImmutable;

class NflEvidenceLedgerAudit
{
    public function report(int $season, ?string $version = null, ?string $forecastSource = null): array
    {
        $version ??= NflModelVersion::current();
        $canonical = app(NflEvidenceForecastSource::class)->name($forecastSource) === 'canonical';
        $games = Game::with('sportEvent:id,starts_at')->select(['id', 'sport_event_id', 'season', 'season_type', 'name', 'short_name', 'game_date', 'game_time', 'status', 'home_score', 'away_score'])->where('season', $season)->whereIn('season_type', ['2', 'regular'])->get()->keyBy('id');
        if ($canonical) {
            $snapshots = app(NflEvidenceForecastSource::class)->forGames($games, $version);
            $captures = PredictionFeatureSnapshot::query()->where('sport', 'nfl')->where('prediction_table', 'predictions')
                ->where('blend_version', NflEvidenceCapture::NATIVE_POLICY)
                ->whereIn('canonical_prediction_id', $snapshots->pluck('canonical_prediction_id'))
                ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')->where('generated_at', '<=', now())
                ->orderByDesc('generated_at')->orderByDesc('id')->get([
                    'id', 'game_id', 'canonical_prediction_id', 'prediction_id',
                    'features->evidence->canonical_lineage->calculation_run_id as source_run',
                    'features->evidence->canonical_lineage->event_input_snapshot_id as source_input',
                ]);
            foreach ($captures as $capture) {
                $forecast = $snapshots->get($capture->game_id);
                if ($forecast && $forecast->id === null && $forecast->canonical_prediction_id === $capture->canonical_prediction_id
                    && (int) $capture->prediction_id === $forecast->canonical_prediction_id
                    && $capture->source_run === $forecast->calculation_run_id
                    && (int) $capture->source_input === $forecast->event_input_snapshot_id) {
                    $forecast->id = $capture->id;
                }
            }
            $coverage = NflSignalObservation::query()
                ->join('prediction_feature_snapshots as capture', 'capture.id', '=', 'nfl_signal_observations.prediction_feature_snapshot_id')
                ->whereIn('capture.id', $snapshots->pluck('id')->filter())
                ->whereColumn('capture.canonical_prediction_id', 'nfl_signal_observations.canonical_prediction_id')
                ->whereNull('nfl_signal_observations.prediction_id');
            $observations = (clone $coverage)->selectRaw('prediction_feature_snapshot_id, COUNT(*) as observations')
                ->groupBy('prediction_feature_snapshot_id')->get()->keyBy('prediction_feature_snapshot_id');
            $gradeable = (clone $coverage)->where(fn ($query) => $query
                ->where('nfl_signal_observations.signal_type', '!=', 'matchup_condition')
                ->orWhereIn('nfl_signal_observations.signal_payload->state', ['matched', 'not_matched']))
                ->selectRaw('prediction_feature_snapshot_id, COUNT(*) as expected')->groupBy('prediction_feature_snapshot_id')
                ->get()->keyBy('prediction_feature_snapshot_id');
            foreach ($observations as $id => $row) {
                $row->gradeable = (int) ($gradeable->get($id)?->expected ?? 0);
            }
            $grades = NflSignalGrade::query()->join('nfl_signal_observations as o', 'o.id', '=', 'nfl_signal_grades.nfl_signal_observation_id')
                ->whereIn('o.id', (clone $coverage)->select('nfl_signal_observations.id'))->where('evaluation_source', 'outcome')
                ->selectRaw('o.prediction_feature_snapshot_id, nfl_signal_grades.market_type, COUNT(*) as grade_count')
                ->groupBy('o.prediction_feature_snapshot_id', 'nfl_signal_grades.market_type')->get()
                ->keyBy(fn ($row) => $row->prediction_feature_snapshot_id.':'.$row->market_type);
        } else {
            $latest = PredictionFeatureSnapshot::where('sport', 'nfl')->where('prediction_table', 'nfl_predictions')
                ->where('model_version', $version)->whereIn('game_id', $games->keys())->where('blend_version', '!=', NflEvidenceCapture::POLICY)
                ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')->where('generated_at', '<=', now())
                ->whereColumn('generated_at', '<', 'game_start_at')->whereColumn('features_available_at', '<=', 'generated_at')
                ->selectRaw('MAX(id)')->groupBy('game_id');
            $snapshots = PredictionFeatureSnapshot::whereIn('id', $latest)->get(['id', 'game_id', 'generated_at', 'game_start_at', 'outputs', 'market_context'])->keyBy('game_id');
            $observations = NflSignalObservation::whereIn('prediction_feature_snapshot_id', $snapshots->pluck('id'))
                ->selectRaw('prediction_feature_snapshot_id, COUNT(*) as observations, MAX(created_at) as materialized_at')
                ->groupBy('prediction_feature_snapshot_id')->get()->keyBy('prediction_feature_snapshot_id');
            $grades = NflSignalGrade::join('nfl_signal_observations as o', 'o.id', '=', 'nfl_signal_grades.nfl_signal_observation_id')
                ->whereIn('o.prediction_feature_snapshot_id', $snapshots->pluck('id'))->where('evaluation_source', 'outcome')
                ->selectRaw('o.prediction_feature_snapshot_id, nfl_signal_grades.market_type, COUNT(*) as grade_count, MAX(graded_at) as latest_grade')
                ->groupBy('o.prediction_feature_snapshot_id', 'nfl_signal_grades.market_type')->get()
                ->keyBy(fn ($r) => $r->prediction_feature_snapshot_id.':'.$r->market_type);
        }
        $reasons = [];
        $details = [];
        foreach ($games as $game) {
            $snapshot = $snapshots->get($game->id);
            $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game);
            foreach (['winner', 'spread', 'total'] as $market) {
                $field = ['winner' => 'win_probability', 'spread' => 'predicted_spread', 'total' => 'predicted_total'][$market];
                $reason = match (true) {
                    $game->status !== 'STATUS_FINAL' || $game->home_score === null || $game->away_score === null => 'awaiting_final_result',
                    $snapshot === null => 'missing_verified_pregame_snapshot',
                    $kickoff === null || ! $snapshot->game_start_at?->eq($kickoff) => 'kickoff_mismatch',
                    ! is_numeric($snapshot->outputs[$field] ?? null) => 'missing_forecast_value',
                    $market !== 'winner' && ! is_numeric($snapshot->market_context[$market === 'spread' ? 'market_home_margin' : 'market_total'] ?? null) => 'missing_market_line',
                    $canonical && ! $snapshot->capture_eligible => 'missing_capture_source_lineage',
                    ! $observations->has($snapshot->id) => 'not_materialized',
                    $canonical && (int) $observations[$snapshot->id]->gradeable === 0 => 'no_gradeable_conditions',
                    (int) ($grades->get($snapshot->id.':'.$market)?->grade_count ?? 0) < (int) ($canonical ? $observations[$snapshot->id]->gradeable : $observations[$snapshot->id]->observations) => 'grading_backlog',
                    default => 'graded',
                };
                $quote = $canonical
                    ? data_get($snapshot?->market_context, 'quote_times.'.$market)
                    : ($snapshot?->market_context['odds_updated_at'] ?? null);
                $quoteStatus = 'missing_observation_time';
                if ($quote !== null && $snapshot) {
                    try {
                        $time = CarbonImmutable::parse($quote);
                        $quoteStatus = $time->gt($snapshot->generated_at) ? 'after_forecast' : ($time->lt($snapshot->generated_at->toImmutable()->subHours(36)) ? 'stale' : 'fresh');
                    } catch (\Throwable) {
                        $quoteStatus = 'invalid_observation_time';
                    }
                }
                $reasons[$market][$reason] = ($reasons[$market][$reason] ?? 0) + 1;
                $details[] = ['game_id' => $game->id, 'matchup' => $game->short_name ?: $game->name, 'snapshot_id' => $snapshot?->id, 'market' => $market, 'reason' => $reason, 'quote_status' => $quoteStatus,
                    ...($canonical ? [
                        'canonical_prediction_id' => $snapshot?->canonical_prediction_id,
                        'canonical_prediction_public_id' => $snapshot?->canonical_prediction_public_id,
                        'calculation_run_id' => $snapshot?->calculation_run_id,
                        'event_input_snapshot_id' => $snapshot?->event_input_snapshot_id,
                    ] : [])];
            }
        }
        $scope = NflSignalObservation::where('season', $season)->where('model_version', $version)
            ->when($canonical, fn ($query) => $query->whereNotNull('canonical_prediction_id'), fn ($query) => $query->whereNull('canonical_prediction_id'));
        $heartbeat = CommandHeartbeat::where('command', 'like', '%nfl:%signal%')->get();

        return ['checked_at' => now()->toIso8601String(), 'season' => $season, 'model_version' => $version,
            'forecast_source' => $canonical ? 'canonical' : 'legacy', 'evidence_capture_source' => $canonical ? 'canonical' : 'legacy_feature_snapshots',
            'canonical_evidence_capture' => 'implemented_tracking_only', 'game_count' => $games->count(), 'reasons' => $reasons, 'games' => $details,
            'freshness' => ['last_forecast' => $snapshots->max('generated_at'),
                'last_capture' => PredictionFeatureSnapshot::where('sport', 'nfl')->where('model_version', $version)->where('blend_version', $canonical ? NflEvidenceCapture::NATIVE_POLICY : NflEvidenceCapture::POLICY)->max('generated_at'),
                'last_materialization' => (clone $scope)->max('created_at'),
                'last_grade' => NflSignalGrade::whereIn('nfl_signal_observation_id', (clone $scope)->select('id'))->max('graded_at')],
            'scheduler_heartbeats' => $heartbeat->toArray(), 'sample_unit' => 'game', 'read_only' => true];
    }

    public function usesCanonicalForecasts(): bool
    {
        return app(NflEvidenceForecastSource::class)->usesCanonical();
    }
}
