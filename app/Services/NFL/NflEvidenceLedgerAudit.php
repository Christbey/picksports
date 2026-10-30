<?php

namespace App\Services\NFL;

use App\Models\CommandHeartbeat;
use App\Models\NFL\Game;
use App\Models\NflSignalGrade;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Support\NflModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class NflEvidenceLedgerAudit
{
    public function report(int $season, ?string $version = null): array
    {
        $version ??= NflModelVersion::current();
        $canonical = $this->usesCanonicalForecasts();
        $games = Game::with('sportEvent:id,starts_at')->select(['id', 'sport_event_id', 'season', 'season_type', 'name', 'short_name', 'game_date', 'game_time', 'status', 'home_score', 'away_score'])->where('season', $season)->whereIn('season_type', ['2', 'regular'])->get()->keyBy('id');
        if ($canonical) {
            $snapshots = $this->canonicalForecasts($games, $version);
            $observations = collect();
            $grades = collect();
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
                    ! $observations->has($snapshot->id) => 'not_materialized',
                    (int) ($grades->get($snapshot->id.':'.$market)?->grade_count ?? 0) < (int) $observations[$snapshot->id]->observations => 'grading_backlog',
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
        $scope = NflSignalObservation::where('season', $season)->where('model_version', $version);
        $heartbeat = CommandHeartbeat::where('command', 'like', '%nfl:%signal%')->get();

        return ['checked_at' => now()->toIso8601String(), 'season' => $season, 'model_version' => $version,
            'forecast_source' => $canonical ? 'canonical' : 'legacy', 'evidence_capture_source' => 'legacy_feature_snapshots',
            'canonical_evidence_capture' => 'not_migrated', 'game_count' => $games->count(), 'reasons' => $reasons, 'games' => $details,
            'freshness' => ['last_forecast' => $snapshots->max('generated_at'),
                'last_capture' => PredictionFeatureSnapshot::where('sport', 'nfl')->where('model_version', $version)->where('blend_version', NflEvidenceCapture::POLICY)->max('generated_at'),
                'last_materialization' => (clone $scope)->max('created_at'),
                'last_grade' => NflSignalGrade::whereIn('nfl_signal_observation_id', (clone $scope)->select('id'))->max('graded_at')],
            'scheduler_heartbeats' => $heartbeat->toArray(), 'sample_unit' => 'game', 'read_only' => true];
    }

    public function usesCanonicalForecasts(): bool
    {
        return (bool) config('prediction_lifecycle.canonical_pipeline.nfl', false)
            || (bool) config('prediction_lifecycle.canonical_reads.nfl', false);
    }

    /** @param Collection<int, Game> $games @return Collection<int, object> */
    private function canonicalForecasts(Collection $games, string $version): Collection
    {
        $forecasts = collect();
        if ($games->isEmpty()) {
            return $forecasts;
        }
        app(CanonicalSportPredictionQuery::class)->queryForSport('nfl')
            ->whereIn('nfl_games.id', $games->keys())
            ->where('predictions.generated_at', '<=', now())
            ->select(['predictions.id', 'predictions.public_id', 'predictions.sport', 'predictions.sport_event_id',
                'predictions.calculation_run_id', 'predictions.phase', 'predictions.publication_state',
                'predictions.generated_at', 'predictions.published_at', 'predictions.model_version'])
            ->withOnly([
                'markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
                'calculationRun.release:id,status', 'sportEvent:id,starts_at', 'sportEvent.nflGame:id,sport_event_id',
                'calculationRun.inputSnapshot' => fn (BelongsTo $query): BelongsTo => $query->select([
                    'id', 'sport', 'sport_event_id', 'phase', 'pregame_safety_status', 'captured_at', 'cutoff_at',
                    'latest_source_available_at', 'content_hash', 'schema_version',
                    'inputs->event->starts_at as audit_game_start_at',
                    'inputs->recommendation_context->version as audit_context_version',
                    'inputs->recommendation_context->model_version as audit_model_version',
                    'inputs->recommendation_context->outputs as audit_outputs',
                    'inputs->pregame_market->available as audit_market_available',
                    'inputs->pregame_market->consensus as audit_market_consensus',
                ])->withCasts(['audit_outputs' => 'array', 'audit_market_consensus' => 'array']),
            ])->chunk(25, function (Collection $predictions) use ($forecasts, $version): void {
                foreach ($predictions as $prediction) {
                    $input = $prediction->calculationRun?->inputSnapshot;
                    if ($input?->getAttribute('audit_context_version') !== NflRecommendationInputBuilder::VERSION
                        || $input?->getAttribute('audit_model_version') !== $version) {
                        continue;
                    }
                    $start = $input->getAttribute('audit_game_start_at');
                    if (! is_string($start) || $start === '') {
                        continue;
                    }
                    try {
                        $frozenStart = CarbonImmutable::parse($start);
                    } catch (\Throwable) {
                        continue;
                    }
                    $snapshot = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
                    if ($snapshot === null || $prediction->published_at->gte($frozenStart)
                        || $prediction->generated_at->gte($frozenStart) || $input->captured_at->gt($frozenStart)
                        || $input->cutoff_at->gt($frozenStart)
                        || $forecasts->has($snapshot->game_id)) {
                        continue;
                    }
                    $market = in_array($input->getAttribute('audit_market_available'), [true, 1, '1', 'true'], true)
                        ? (array) $input->getAttribute('audit_market_consensus') : [];
                    $quoteTimes = [];
                    foreach (['winner' => 'moneyline', 'spread' => 'spread', 'total' => 'total'] as $type => $key) {
                        $quoteTimes[$type] = data_get($market, $key.'.freshness_observed_at')
                            ?? data_get($market, $key.'.provider_observed_at')
                            ?? data_get($market, $key.'.captured_at');
                    }
                    $line = data_get($market, 'spread.line');
                    $forecasts->put($snapshot->game_id, (object) [
                        'id' => null, 'canonical_prediction_id' => $prediction->id,
                        'canonical_prediction_public_id' => $prediction->public_id,
                        'calculation_run_id' => $prediction->calculation_run_id, 'event_input_snapshot_id' => $input->id,
                        'game_id' => $snapshot->game_id, 'generated_at' => $prediction->generated_at,
                        'game_start_at' => $frozenStart, 'outputs' => (array) $input->getAttribute('audit_outputs'),
                        'market_context' => ['market_home_margin' => is_numeric($line) ? -(float) $line : null,
                            'market_total' => data_get($market, 'total.line'), 'quote_times' => $quoteTimes],
                    ]);
                }
            });

        return $forecasts;
    }
}
