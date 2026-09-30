<?php

namespace Audit;

require_once __DIR__.'/NflHistoricalBaselineAudit.php';

/** Read-only, isolated Elo sensitivity study; never a forecast promotion. */
final class NflSeasonalWeightingStudy
{
    public static function analyze(array $games, array $config): array
    {
        $games = array_values(array_filter($games, fn ($g) => $g['season'] >= 2009 && $g['season'] <= 2025
            && in_array($g['type'], ['2', '3'], true)));
        $variants = [];
        $baselineKey = 'regression_0.33_early_k_1.1';
        foreach ([0.20, 0.33, 0.50, 0.67] as $regression) {
            foreach ([1.1, 1.5, 2.0] as $earlyK) {
                $key = 'regression_'.rtrim(rtrim(sprintf('%.2f', $regression), '0'), '.').'_early_k_'.rtrim(rtrim(sprintf('%.1f', $earlyK), '0'), '.');
                $candidateConfig = array_replace($config, ['offseason_regression_factor' => $regression, 'recency_multiplier' => $earlyK]);
                $rows = NflHistoricalBaselineAudit::simulate($games, $candidateConfig);
                foreach ($rows as &$row) {
                    $row['probability'] = 1 / (1 + pow(10, -($row['elo_difference'] + ($row['neutral'] ? 0 : $candidateConfig['home_field_advantage'])) / 400));
                }
                unset($row);
                $scope = fn (int $first, int $last, bool $early) => array_values(array_filter($rows,
                    fn ($r) => $r['type'] === '2' && $r['season'] >= $first && $r['season'] <= $last && (! $early || $r['week'] <= 4)));
                $variants[$key] = ['offseason_regression' => $regression, 'early_season_k_multiplier' => $earlyK,
                    'training_2010_2020_early' => self::metrics($scope(2010, 2020, true)),
                    'holdout_2021_2025_early' => self::metrics($scope(2021, 2025, true)),
                    'holdout_2021_2025_all' => self::metrics($scope(2021, 2025, false)),
                    'holdout_by_season_early' => []];
                for ($season = 2021; $season <= 2025; $season++) {
                    $variants[$key]['holdout_by_season_early'][$season] = self::metrics($scope($season, $season, true));
                }
            }
        }
        // Selection uses early-season training MAE only; holdout scores never choose a candidate.
        $selected = $baselineKey;
        foreach ($variants as $key => $variant) {
            if ($variant['training_2010_2020_early']['mae'] < $variants[$selected]['training_2010_2020_early']['mae']) {
                $selected = $key;
            }
        }
        $coverage = [];
        foreach ($games as $g) {
            $season = $g['season'];
            $coverage[$season]['games'] = ($coverage[$season]['games'] ?? 0) + 1;
            $coverage[$season]['missing_qb_team_games'] = ($coverage[$season]['missing_qb_team_games'] ?? 0)
                + (int) empty($g['home_qb_name']) + (int) empty($g['away_qb_name']);
        }

        return ['production_modified' => false, 'promotion_allowed' => false,
            'base_elo_config' => $config,
            'input_sha256' => hash('sha256', json_encode($games, JSON_THROW_ON_ERROR)),
            'scope' => 'Isolated score-driven Elo reconstruction, not full production prediction replay.',
            'policies' => ['2009 warm-up; 2010–2020 training; 2021–2025 chronological holdout; all 2026 outcomes excluded.',
                'Forecasts frozen before any same-date rating updates; regular/postseason outcomes update Elo; preseason excluded.',
                'Candidate selected only by training weeks 1–4 margin MAE; fixed candidate grid; no holdout refitting.',
                'Margin mapping fixed to existing historical baseline audit: 0.09 points per Elo, 2.25 home points, ±15 cap.',
                'Brier/log loss use Elo win probability; ties excluded from binary calibration, retained in margin errors.',
                'Current config has prior historical tuning exposure; this is retrospective sensitivity, not a pristine independent validation.',
                'QB identity coverage is audited separately. Actual target starters are not model features; no current QB/form/market/roster layers are replayed.'],
            'baseline' => $baselineKey, 'selected_on_training' => $selected, 'coverage' => $coverage, 'variants' => $variants];
    }

    private static function metrics(array $rows): array
    {
        $sum = $squared = $bias = $brier = $logLoss = 0.0;
        $binary = $correct = 0;
        $bins = [];
        foreach ($rows as $row) {
            $margin = round($row['sequential_baseline'], 1);
            $error = $margin - $row['actual'];
            $sum += abs($error);
            $squared += $error ** 2;
            $bias += $error;
            if ($row['actual'] == 0) {
                continue;
            }
            $actual = (int) ($row['actual'] > 0);
            $p = max(1e-12, min(1 - 1e-12, $row['probability']));
            $binary++;
            $correct += (int) (($p >= .5) === (bool) $actual);
            $brier += ($p - $actual) ** 2;
            $logLoss -= $actual * log($p) + (1 - $actual) * log(1 - $p);
            $bin = min(9, (int) floor($p * 10));
            $bins[$bin]['games'] = ($bins[$bin]['games'] ?? 0) + 1;
            $bins[$bin]['predicted_sum'] = ($bins[$bin]['predicted_sum'] ?? 0) + $p;
            $bins[$bin]['home_wins'] = ($bins[$bin]['home_wins'] ?? 0) + $actual;
        }
        ksort($bins);
        foreach ($bins as &$bin) {
            $bin['mean_prediction'] = $bin['predicted_sum'] / $bin['games'];
            $bin['observed_rate'] = $bin['home_wins'] / $bin['games'];
            unset($bin['predicted_sum']);
        }
        unset($bin);
        $n = count($rows);

        return ['games' => $n, 'mae' => $n ? $sum / $n : null, 'rmse' => $n ? sqrt($squared / $n) : null,
            'bias' => $n ? $bias / $n : null, 'binary_games' => $binary,
            'accuracy' => $binary ? $correct / $binary : null, 'brier' => $binary ? $brier / $binary : null,
            'log_loss' => $binary ? $logLoss / $binary : null, 'calibration_bins' => $bins];
    }
}
