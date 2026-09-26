<?php

namespace Audit;

/** Offline comparison only. Never changes production data or chooses a release. */
final class NflIntegrityChangeImpact
{
    public static function compare(array $before, array $after, string $field): array
    {
        $afterById = array_column($after, null, 'id');
        $changes = ['margin_changed' => 0, 'pick_changed' => 0, 'result_changed' => 0, 'max_margin_change' => 0.0];
        foreach ($before as $r) {
            $new = $afterById[$r['id']];
            $a = round((float) $r[$field], 1);
            $b = round((float) $new[$field], 1);
            $delta = abs($a - $b);
            $changes['margin_changed'] += (int) ($delta > 0.00001);
            $changes['max_margin_change'] = max($changes['max_margin_change'], $delta);
            $oldEdge = $a + $r['line'];
            $newEdge = $b + $new['line'];
            $side = fn ($e) => abs($e) < 0.00001 ? 0 : ($e > 0 ? 1 : -1);
            $changes['pick_changed'] += (int) ($side($oldEdge) !== $side($newEdge));
            $changes['result_changed'] += (int) (NflHistoricalBaselineAudit::grade($a, $r['line'], $r['actual']) !== NflHistoricalBaselineAudit::grade($b, $new['line'], $new['actual']));
        }

        return ['before' => NflHistoricalBaselineAudit::summary($before, $field),
            'after' => NflHistoricalBaselineAudit::summary($after, $field), 'changes' => $changes];
    }

    public static function calibration(array $rows, float $hfa): array
    {
        $matched = array_values(array_filter($rows, fn ($r) => $r['home_prior'] !== null && $r['away_prior'] !== null));
        $report = ['games_seen' => count($rows), 'matched_games' => count($matched), 'excluded_missing_prior' => count($rows) - count($matched),
            'same_game_rating_used_by_old_lookup' => count(array_filter($rows, fn ($r) => $r['home_old_is_current_game'] || $r['away_old_is_current_game'])),
            'scope' => 'Same regular-season games; original command also included postseason. No factor promoted. Best grid results are in-sample, not forecast accuracy.'];
        foreach (['old_same_day_uncapped', 'prior_only_uncapped', 'prior_capped_rounded'] as $variant) {
            $grid = [];
            for ($i = 0; $i <= 28; $i++) {
                $factor = 0.01 + $i * 0.005;
                $sum = 0.0;
                foreach ($matched as $r) {
                    $old = $variant === 'old_same_day_uncapped';
                    $diff = $r[$old ? 'home_old' : 'home_prior'] - $r[$old ? 'away_old' : 'away_prior'] + ($r['neutral'] ? 0 : $hfa);
                    $margin = $diff * $factor;
                    if ($variant === 'prior_capped_rounded') {
                        $margin = round(max(-15, min(15, $margin)), 1);
                    }
                    $sum += abs($margin - $r['actual']);
                }
                $grid[] = ['factor' => round($factor, 4), 'mae' => $sum / max(1, count($matched))];
            }
            $report[$variant]['at_current_factor'] = $grid[16]; // 0.090, unchanged production factor.
            usort($grid, fn ($a, $b) => $a['mae'] <=> $b['mae']);
            $report[$variant]['best_in_sample'] = $grid[0];
        }

        return $report;
    }

    public static function run(array $archive, array $calibration): array
    {
        $rows = $archive['rows'];
        $report = ['source_audited_at' => $archive['audited_at'], 'calibration_extracted_at' => $calibration['extracted_at'],
            'production_modified' => false, 'live_model_rerun' => false,
            'line_policy' => 'Only line signs change; margins fixed. Re-select side and grade against each convention. Wrong-sign results are invalid diagnostics, not prior published performance.',
            'tie_policy' => 'Two fresh simulations start in 2009 with identical fixed inputs, season regression and rounding; only tie credit differs. Not a repair or exact replay of stored Elo history.'];
        $matched = array_values(array_filter($rows, fn ($r) => $r['season'] >= 2021 && $r['season'] <= 2025 && $r['type'] === '2' && $r['stored_elo_baseline'] !== null && $r['snapshot_model'] !== null && $r['line_source'] === 'nflverse_archive_normalized'));
        $wrong = array_map(fn ($r) => array_replace($r, ['line' => -$r['line']]), $matched);
        foreach (['stored_elo_baseline', 'snapshot_model'] as $field) {
            $report['line_sign_2021_2025'][$field] = self::compare($wrong, $matched, $field);
        }
        $newSim = NflHistoricalBaselineAudit::simulate($rows, $archive['elo_config']);
        $oldSim = NflHistoricalBaselineAudit::simulate($rows, $archive['elo_config'], false);
        $report['tie_games'] = array_values(array_map(fn ($r) => array_intersect_key($r, array_flip(['id', 'season', 'date', 'home', 'away'])), array_filter($rows, fn ($r) => $r['actual'] == 0)));
        foreach (['2010_2025' => [2010, 2025], '2021_2025' => [2021, 2025], '2026_partial' => [2026, 2026]] as $scope => [$from, $to]) {
            $filter = fn ($r) => $r['season'] >= $from && $r['season'] <= $to && $r['type'] === '2';
            $report['ties'][$scope] = self::compare(array_values(array_filter($oldSim, $filter)), array_values(array_filter($newSim, $filter)), 'sequential_baseline');
        }
        $report['calibration_all'] = self::calibration($calibration['rows'], $calibration['hfa']);
        foreach (collect($calibration['rows'])->groupBy('season') as $year => $seasonRows) {
            $report['calibration_by_season'][$year] = self::calibration($seasonRows->all(), $calibration['hfa']);
        }

        return $report;
    }
}
