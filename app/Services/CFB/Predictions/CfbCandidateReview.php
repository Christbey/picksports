<?php

namespace App\Services\CFB\Predictions;

use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;

class CfbCandidateReview
{
    public function __construct(private readonly CfbCandidateEvidence $evidence) {}

    public function suite(CanonicalPrediction $prediction): array
    {
        if (! $this->safe($prediction)) {
            return ['status' => 'unsafe_or_missing_pregame_snapshot', 'models' => [], 'market' => []];
        }
        $frozen = data_get($prediction->output_metadata, 'comparison_forecasts');
        if (($frozen['version'] ?? null) === CfbForecastCandidates::VERSION) {
            return $frozen;
        }
        $snapshot = $prediction->calculationRun?->inputSnapshot;
        $event = $prediction->sportEvent;
        $game = $event?->cfbGame;
        if (! $snapshot || ! $event?->starts_at || ! $game || ! $this->safe($prediction)) {
            return ['status' => 'unsafe_or_missing_pregame_snapshot', 'models' => [], 'market' => []];
        }
        $line = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home')?->projected_line;
        $total = $prediction->markets->first(fn ($m) => $m->market_type === 'total')?->projected_line;
        if (! is_numeric($line) || ! is_numeric($total)) {
            return ['status' => 'missing_projection', 'models' => [], 'market' => []];
        }
        $evidence = $this->evidence->historical($game, $snapshot->captured_at, $event->starts_at);
        $market = app(CfbFrozenMarketEvidence::class)->capture($game, $snapshot->captured_at, $event->starts_at);

        return app(CfbForecastCandidates::class)->build((float) $line, (float) $total, $evidence, $market);
    }

    public function safe(CanonicalPrediction $prediction): bool
    {
        $s = $prediction->calculationRun?->inputSnapshot;
        $k = $prediction->sportEvent?->starts_at;

        return $s && $k && $s->pregame_safety_status === 'verified' && $s->captured_at && $s->captured_at->lt($k)
            && $s->cutoff_at && $s->captured_at->lte($s->cutoff_at) && $s->cutoff_at->lte($k)
            && $s->created_at && $s->created_at->lt($k) && $prediction->created_at && $prediction->created_at->lt($k)
            && $prediction->generated_at && $prediction->generated_at->lt($k) && $prediction->published_at && $prediction->published_at->lt($k)
            && (! $s->latest_source_available_at || $s->latest_source_available_at->lte($s->captured_at));
    }

    public function report(int $season, ?string $from = null, ?string $to = null): array
    {
        $predictions = app(CanonicalSportPredictionQuery::class)->queryForSport('cfb', array_filter([
            'season' => $season, 'season_type' => '2', 'from_date' => $from, 'to_date' => $to,
        ]))->where('cfb_games.status', 'STATUS_FINAL')->where('sport_events.starts_at', '<', now()->utc())
            ->reorder()->orderBy('predictions.id')->lazy(20);
        $rows = $excluded = $seen = $probabilities = [];
        foreach ($predictions as $prediction) {
            $game = $prediction->sportEvent->cfbGame;
            if (isset($seen[$game->id])) {
                continue;
            }
            $seen[$game->id] = true;
            if (! $this->safe($prediction) || $game->home_score === null || $game->away_score === null) {
                $excluded['unsafe_snapshot_or_missing_result'] = ($excluded['unsafe_snapshot_or_missing_result'] ?? 0) + 1;

                continue;
            }
            $suite = $this->suite($prediction);
            $grades = app(CfbForecastCandidates::class)->grade($suite, $game->home_score, $game->away_score);
            $release = $prediction->calculationRun->release;
            $rows[] = ['game_id' => $game->id, 'prediction_id' => $prediction->public_id, 'release' => $release->semantic_version,
                'configuration_hash' => $release->configuration_hash, 'kickoff' => $prediction->sportEvent->starts_at->toIso8601String(),
                'week' => $game->week, 'baseline' => data_get($prediction->output_metadata, 'feature_coverage.spread_baseline', 'unknown'), 'evidence_status' => data_get($suite, 'evidence.status'),
                'evidence_run_id' => data_get($suite, 'evidence.run_id'), 'market' => $suite['market'], 'grades' => $grades,
                'prospective_candidates' => data_get($prediction->output_metadata, 'comparison_forecasts.version') === CfbForecastCandidates::VERSION];
            $probability = $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')?->probability;
            if (is_numeric($probability) && $probability > 0 && $probability < 1 && $game->home_score !== $game->away_score) {
                $key = $release->semantic_version.':'.$release->configuration_hash;
                $probabilities[$key][] = ['game_id' => $game->id, 'kickoff' => $prediction->sportEvent->starts_at->toIso8601String(),
                    'pregame_safe' => true, 'feature_model_win_probability' => (float) $probability,
                    'target_home_win' => $game->home_score > $game->away_score ? 1 : 0];
            }
            $prediction->unsetRelations();
        }
        $calibration = [];
        foreach ($probabilities as $release => $examples) {
            $calibration[$release] = app(CfbMoneylineTemporalCalibration::class)->train($examples);
        }
        $cohorts = ['all' => $rows];
        foreach (collect($rows)->groupBy('release') as $release => $group) {
            $cohorts['release:'.$release] = $group->all();
        }
        foreach (collect($rows)->groupBy('baseline') as $baseline => $group) {
            $cohorts['baseline:'.$baseline] = $group->all();
        }
        foreach (collect($rows)->groupBy('week') as $week => $group) {
            $cohorts['week:'.$week] = $group->all();
        }
        $reports = [];
        foreach ($cohorts as $key => $cohort) {
            $reports[$key] = $this->summarize($cohort);
        }

        return ['version' => CfbForecastCandidates::VERSION, 'season' => $season, 'created_at' => now()->toIso8601String(),
            'games' => count($rows), 'excluded' => $excluded, 'cohorts' => $reports, 'probability_calibration' => $calibration,
            'promotion' => ['allowed' => false, 'reason' => 'Prospective frozen candidate observations and independent validation are required; no automatic promotion.'],
            'policy' => ['one_latest_safe_published_prediction_per_game', 'only_quotes_observed_and_stored_by_forecast_capture',
                'same_game_pairs_for_each_candidate_and_metric', 'missing_data_is_excluded_not_imputed',
                'fixed_candidate_coefficients_not_selected_on_this_report', 'raw_direction_records_are_not_approved_bet_roi',
                'release_specific_date_separated_probability_holdouts'], 'rows' => $rows];
    }

    private function summarize(array $rows): array
    {
        $models = [];
        foreach (['independent', 'opponent_adjusted_total', 'result_rating', 'market_half_blend', 'market'] as $model) {
            $grades = collect($rows)->pluck('grades.'.$model)->filter();
            $models[$model] = ['games' => $grades->count(),
                'spread' => $this->metric($grades->pluck('spread_absolute_error')->all()),
                'total' => $this->metric($grades->pluck('total_absolute_error')->all()),
                'total_bias_actual_minus_model' => $this->metric($grades->pluck('total_signed_error')->all()),
                'spread_directions' => $grades->pluck('spread.result')->filter()->countBy()->all(),
                'total_directions' => $grades->pluck('over_under.result')->filter()->countBy()->all()];
        }
        $pairs = [];
        foreach (['opponent_adjusted_total', 'result_rating', 'market_half_blend', 'market'] as $model) {
            foreach (['spread_absolute_error', 'total_absolute_error'] as $metric) {
                $paired = array_values(array_filter($rows, fn ($row) => is_numeric(data_get($row, 'grades.independent.'.$metric))
                    && is_numeric(data_get($row, 'grades.'.$model.'.'.$metric))));
                $base = array_map(fn ($r) => data_get($r, 'grades.independent.'.$metric), $paired);
                $candidate = array_map(fn ($r) => data_get($r, 'grades.'.$model.'.'.$metric), $paired);
                $pairs[$model][$metric] = ['n' => count($paired), 'game_ids' => array_column($paired, 'game_id'),
                    'independent_mae' => $this->metric($base)['mean'], 'candidate_mae' => $this->metric($candidate)['mean'],
                    'paired_improvement' => $this->metric(array_map(fn ($b, $c) => $b - $c, $base, $candidate))];
            }
        }

        return ['games' => count($rows), 'prospective_candidate_games' => count(array_filter($rows, fn ($r) => $r['prospective_candidates'])),
            'models' => $models, 'paired_comparisons' => $pairs];
    }

    private function metric(array $values): array
    {
        $values = array_values(array_filter($values, 'is_numeric'));
        $n = count($values);
        if (! $n) {
            return ['n' => 0, 'mean' => null, 'lower_95' => null, 'upper_95' => null];
        }
        $mean = array_sum($values) / $n;
        $se = $n > 1 ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1) / $n) : null;

        return ['n' => $n, 'mean' => round($mean, 4), 'lower_95' => $se === null ? null : round($mean - 1.96 * $se, 4),
            'upper_95' => $se === null ? null : round($mean + 1.96 * $se, 4), 'uncertainty_method' => 'normal_approximation_not_team_clustered'];
    }
}
