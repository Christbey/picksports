<?php

namespace Audit;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;

/** Standalone read-only diagnostic; not an application forecast or promotion. */
final class NflHistoricalBaselineAudit
{
    public static function grade(float $margin, ?float $homeLine, float $actual): ?string
    {
        if ($homeLine === null) {
            return null;
        }
        $edge = round($margin, 1) + $homeLine;
        if (abs($edge) < 0.00001) {
            return 'no_pick';
        }
        $cover = ($actual + $homeLine) * ($edge > 0 ? 1 : -1);

        return abs($cover) < 0.00001 ? 'push' : ($cover > 0 ? 'win' : 'loss');
    }

    public static function margin(float $difference, bool $neutral, float $slope = 0.09, float $homePoints = 2.25): float
    {
        return max(-15, min(15, $difference * $slope + ($neutral ? 0 : $homePoints)));
    }

    public static function summary(array $rows, string $field): array
    {
        $out = ['games' => 0, 'win' => 0, 'loss' => 0, 'push' => 0, 'no_pick' => 0, 'missing_line' => 0,
            'absolute_error_sum' => 0.0, 'signed_error_sum' => 0.0, 'winner_correct' => 0, 'winner_games' => 0, 'actual_ties' => 0];
        foreach ($rows as $r) {
            if (! isset($r[$field]) || ! is_numeric($r[$field]) || ! is_finite((float) $r[$field])) {
                continue;
            }
            $m = round((float) $r[$field], 1);
            $out['games']++;
            $out['absolute_error_sum'] += abs($m - $r['actual']);
            $out['signed_error_sum'] += $m - $r['actual'];
            $out[self::grade($m, $r['line'], $r['actual']) ?? 'missing_line']++;
            if ($r['actual'] == 0) {
                $out['actual_ties']++;
            } elseif ($m != 0) {
                $out['winner_games']++;
                $out['winner_correct'] += (int) (($m > 0) === ($r['actual'] > 0));
            }
        }
        $out['ats_win_rate'] = $out['win'] + $out['loss'] ? $out['win'] / ($out['win'] + $out['loss']) : null;
        $out['mae'] = $out['games'] ? $out['absolute_error_sum'] / $out['games'] : null;
        $out['bias'] = $out['games'] ? $out['signed_error_sum'] / $out['games'] : null;
        unset($out['absolute_error_sum'], $out['signed_error_sum']);

        return $out;
    }

    /** Freeze same-date forecasts before updating any ratings. */
    public static function simulate(array $games, array $config, bool $tiesAsHalfWin = true): array
    {
        usort($games, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $ratings = $rows = [];
        $season = null;
        foreach (collect($games)->groupBy('date') as $dateGames) {
            $newSeason = $dateGames[0]['season'];
            if ($season !== null && $newSeason !== $season) {
                foreach ($ratings as &$rating) {
                    $rating = round($rating * (1 - $config['offseason_regression_factor']) + $config['default_rating'] * $config['offseason_regression_factor']);
                }
                unset($rating);
            }
            $season = $newSeason;
            $pending = [];
            foreach ($dateGames as $g) {
                $home = $ratings[$g['home']] ?? $config['default_rating'];
                $away = $ratings[$g['away']] ?? $config['default_rating'];
                $difference = $home - $away;
                $p = 1 / (1 + pow(10, -($difference + ($g['neutral'] ? 0 : $config['home_field_advantage'])) / 400));
                $actual = $g['actual'] == 0 ? ($tiesAsHalfWin ? 0.5 : 0.0) : ($g['actual'] > 0 ? 1.0 : 0.0);
                $k = $config['base_k_factor'] * min($config['max_mov_multiplier'], 1 + log(abs($g['actual']) + 1) * $config['mov_coefficient']);
                if ($g['type'] === '3') {
                    $k *= $config['playoff_multiplier'];
                } elseif ($g['week'] <= $config['recency_weeks']) {
                    $k *= $config['recency_multiplier'];
                }
                $change = round($k * ($actual - $p), 1);
                $pending[$g['home']] = round($home + $change);
                $pending[$g['away']] = round($away - $change);
                $rows[] = $g + ['elo_difference' => $difference, 'sequential_baseline' => self::margin($difference, $g['neutral'])];
            }
            $ratings = array_replace($ratings, $pending);
        }

        return $rows;
    }

    /** Choose mapping on earlier seasons only, never on the evaluated season. */
    public static function fit(array $training): array
    {
        $best = ['slope' => 0.09, 'home_points' => 2.25, 'training_mae' => INF, 'training_games' => count($training)];
        for ($i = 4; $i <= 20; $i++) {
            for ($j = 0; $j <= 8; $j++) {
                $sum = 0.0;
                foreach ($training as $r) {
                    $sum += abs(round(self::margin($r['elo_difference'], $r['neutral'], $i * 0.005, $j * 0.5), 1) - $r['actual']);
                }
                $mae = $sum / max(1, count($training));
                if ($mae < $best['training_mae'] - 0.00000001) {
                    $best = ['slope' => $i * 0.005, 'home_points' => $j * 0.5, 'training_mae' => $mae, 'training_games' => count($training)];
                }
            }
        }

        return $best;
    }

    public function run(array $observedRows, bool $exportOnly = false): array
    {
        $games = Game::query()->where('status', 'STATUS_FINAL')->whereIn('season_type', ['2', '3', 'regular', 'postseason'])
            ->select(['id', 'season', 'season_type', 'week', 'game_date', 'home_team_id', 'away_team_id', 'neutral_site', 'home_score', 'away_score'])
            ->orderBy('game_date')->orderBy('id')->get();
        $elo = EloRating::query()->select(['id', 'team_id', 'date', 'elo_rating', 'created_at'])->orderBy('date')->orderBy('id')->get()
            ->map(fn ($e) => (object) ['team_id' => $e->team_id, 'date' => substr($e->getRawOriginal('date'), 0, 10), 'elo_rating' => (float) $e->elo_rating])->groupBy('team_id');
        $odds = GameOddsSnapshot::query()->where('sport', 'nfl')->where('game_table', 'nfl_games')->where('source', 'nflverse')
            ->select(['id', 'game_id', 'market_context->spread_line as source_margin', 'odds_data->bookmakers[0]->markets[0]->outcomes[0]->point as payload_line'])
            ->orderByDesc('id')->get()->groupBy('game_id');
        $snapshots = PredictionFeatureSnapshot::query()->where('sport', 'nfl')->where('prediction_table', 'nfl_predictions')
            ->where('availability_status', 'verified_reconstruction')->select(['id', 'game_id', 'model_version',
                'model_metadata->legacy->spread as legacy_margin', 'outputs->predicted_spread as model_margin',
                'features->home_elo as home_elo', 'features->away_elo as away_elo'])
            ->orderBy('id')->get()->keyBy('game_id');
        $observed = collect($observedRows)->keyBy('game_id');
        $report = ['audited_at' => now()->toIso8601String(), 'production_modified' => false, 'promotion_allowed' => false,
            'elo_config' => config('nfl.elo'), 'inventory' => [], 'line_sign_mismatches' => 0, 'line_conflicts' => 0,
            'missing_scores' => 0, 'reconstructed_snapshot_default_pairs' => [], 'by_season' => [], 'walk_forward' => []];
        $rows = [];
        foreach ($games as $g) {
            $type = in_array((string) $g->season_type, ['2', 'regular'], true) ? '2' : '3';
            $report['inventory'][$g->season][$type] = ($report['inventory'][$g->season][$type] ?? 0) + 1;
            if ($g->home_score === null || $g->away_score === null) {
                $report['missing_scores']++;

                continue;
            }
            $date = $g->game_date->toDateString();
            $o = $odds->get($g->id, collect());
            $line = null;
            $source = null;
            if ($o->pluck('source_margin')->filter(fn ($v) => is_numeric($v))->unique()->count() > 1) {
                $report['line_conflicts']++;
            } elseif ($o->isNotEmpty() && is_numeric($o->first()->source_margin)) {
                $line = -(float) $o->first()->source_margin;
                $source = 'nflverse_archive_normalized';
                $report['line_sign_mismatches'] += (int) (is_numeric($o->first()->payload_line) && abs((float) $o->first()->payload_line - $line) > 0.00001);
            } elseif ($observed->has($g->id)) {
                $line = $observed[$g->id]['home_line'];
                $source = 'observed_frozen_line';
            }
            $home = $elo->get($g->home_team_id, collect())->last(fn ($e) => $e->date < $date);
            $away = $elo->get($g->away_team_id, collect())->last(fn ($e) => $e->date < $date);
            $s = $snapshots->get($g->id);
            $row = ['id' => $g->id, 'season' => (int) $g->season, 'type' => $type, 'week' => (int) $g->week, 'date' => $date,
                'home' => $g->home_team_id, 'away' => $g->away_team_id, 'neutral' => (bool) $g->neutral_site,
                'actual' => (float) $g->home_score - (float) $g->away_score, 'line' => $line, 'line_source' => $source,
                'market' => $line === null ? null : -$line, 'stored_elo_missing' => (int) ($home === null) + (int) ($away === null),
                'stored_elo_baseline' => $home && $away ? self::margin((float) $home->elo_rating - (float) $away->elo_rating, (bool) $g->neutral_site) : null,
                'snapshot_baseline' => is_numeric($s?->legacy_margin) ? (float) $s->legacy_margin : null,
                'snapshot_model' => is_numeric($s?->model_margin) ? (float) $s->model_margin : null];
            if ($s && (float) $s->home_elo === 1500.0 && (float) $s->away_elo === 1500.0) {
                $report['reconstructed_snapshot_default_pairs'][$g->season] = ($report['reconstructed_snapshot_default_pairs'][$g->season] ?? 0) + 1;
            }
            $rows[] = $row;
        }
        if ($exportOnly) {
            return $report + ['rows' => $rows];
        }

        return self::analyze($rows, $report);
    }

    public static function analyze(array $rows, array $report): array
    {
        $rows = self::simulate($rows, $report['elo_config']);
        foreach (collect($rows)->groupBy('season') as $season => $seasonRows) {
            $regular = $seasonRows->where('type', '2')->all();
            $post = $seasonRows->where('type', '3')->all();
            $report['by_season'][$season] = ['missing_stored_elo_games' => count(array_filter($regular, fn ($r) => $r['stored_elo_missing'] > 0))];
            foreach (['regular' => $regular, 'postseason' => $post] as $scope => $scoped) {
                foreach (['sequential_baseline', 'stored_elo_baseline', 'snapshot_baseline', 'snapshot_model', 'market'] as $field) {
                    $report['by_season'][$season][$scope][$field] = self::summary($scoped, $field);
                }
            }
            if ($season >= 2013) {
                $training = array_values(array_filter($rows, fn ($r) => $r['season'] >= 2010 && $r['season'] < $season && $r['type'] === '2'));
                $fit = self::fit($training);
                $evaluation = array_map(fn ($r) => $r + ['fitted_baseline' => self::margin($r['elo_difference'], $r['neutral'], $fit['slope'], $fit['home_points'])], $regular);
                $report['walk_forward'][$season] = $fit + ['baseline' => self::summary($evaluation, 'sequential_baseline'), 'fitted' => self::summary($evaluation, 'fitted_baseline'), 'market' => self::summary($evaluation, 'market')];
            }
        }
        $report['aggregate'] = [];
        foreach (['all_regular' => fn ($r) => $r['type'] === '2', 'regular_2010_2025' => fn ($r) => $r['type'] === '2' && $r['season'] >= 2010 && $r['season'] <= 2025,
            'stored_elo_matched_2021_2025' => fn ($r) => $r['type'] === '2' && $r['season'] >= 2021 && $r['season'] <= 2025 && $r['stored_elo_baseline'] !== null,
            'reconstructed_snapshots_2021_2025' => fn ($r) => $r['type'] === '2' && $r['season'] >= 2021 && $r['season'] <= 2025 && $r['snapshot_baseline'] !== null] as $scope => $filter) {
            $sample = array_values(array_filter($rows, $filter));
            foreach (['sequential_baseline', 'stored_elo_baseline', 'snapshot_baseline', 'snapshot_model', 'market'] as $field) {
                $report['aggregate'][$scope][$field] = self::summary($sample, $field);
            }
        }
        $report['policies'] = ['source_line' => 'Negate original nflverse spread_line; do not trust reversed sportsbook payload. Archive captured_at is synthetic, not an observation timestamp.',
            'simulation' => 'All teams start at 1500 in 2009; score only prior-date results; update after each date; 33% offseason regression; ties count 0.5, unlike current calculator. Regular and playoff results update Elo; preseason excluded.',
            'stored_elo' => 'Strictly prior-date records only; missing prior ratings excluded. 2021-2025 records were backfilled in 2026, not observed live.',
            'walk_forward' => '2009 warm-up excluded from mapping training. From 2013, fit slope 0.020..0.100 step 0.005 and home points 0..4 step 0.5 by MAE using only earlier regular seasons; Elo update parameters fixed. Prior tuning provenance is unknown: not a pristine independent holdout.',
            'snapshots' => 'Archived full-historical reconstructions are descriptive, not original pregame forecasts or a validated current-model rerun.',
            'grading' => 'Round margins to one decimal before ATS selection; zero edge no-pick; pushes separate; no wager ROI. No automatic deployment or model change.'];

        foreach (['seasons_2013_2025' => [2013, 2025], 'seasons_2024_2025' => [2024, 2025]] as $scope => [$first, $last]) {
            $folds = array_filter($report['walk_forward'], fn ($year) => $year >= $first && $year <= $last, ARRAY_FILTER_USE_KEY);
            foreach (['baseline', 'fitted', 'market'] as $field) {
                $combined = [];
                foreach (['games', 'win', 'loss', 'push', 'no_pick', 'missing_line', 'winner_correct', 'winner_games', 'actual_ties'] as $key) {
                    $combined[$key] = array_sum(array_map(fn ($fold) => $fold[$field][$key], $folds));
                }
                $combined['mae'] = $combined['games'] ? array_sum(array_map(fn ($fold) => $fold[$field]['mae'] * $fold[$field]['games'], $folds)) / $combined['games'] : null;
                $combined['ats_win_rate'] = $combined['win'] + $combined['loss'] ? $combined['win'] / ($combined['win'] + $combined['loss']) : null;
                $report['walk_forward_aggregate'][$scope][$field] = $combined;
            }
        }

        return $report;
    }
}
