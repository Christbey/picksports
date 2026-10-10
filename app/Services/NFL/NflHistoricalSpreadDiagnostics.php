<?php

namespace App\Services\NFL;

class NflHistoricalSpreadDiagnostics
{
    /**
     * Retrospective diagnostics only. These regenerated historical forecasts were
     * not observed before kickoff and cannot validate a different production model.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function evaluate(array $rows, int $evaluationSeason): array
    {
        $report = [
            'evidence_type' => 'retrospective_reconstructed_forecasts',
            'evaluation_from_season' => $evaluationSeason,
            'training_policy' => 'Earlier seasons only, fixed before evaluation; exact model version',
            'line_policy' => 'Archived nflverse closing lines; not observed entry prices or wager ROI',
            'independent_holdout_verified' => false,
            'promotion_allowed' => false,
            'models' => [],
        ];
        foreach (collect($rows)->groupBy('model_version') as $version => $modelRows) {
            $training = $modelRows->filter(fn ($row) => $row['season'] < $evaluationSeason);
            $residuals = $training->map(fn ($row) => $row['actual_margin'] - $row['model_margin'])->values()->all();
            $evaluation = $modelRows->filter(fn ($row) => $row['season'] >= $evaluationSeason);
            $model = ['training_games' => $training->count(), 'evaluation_games' => $evaluation->count(),
                'wins' => 0, 'losses' => 0, 'pushes' => 0, 'no_pick' => 0,
                'model_absolute_error_sum' => 0.0, 'market_absolute_error_sum' => 0.0,
                'probability_games' => 0, 'brier_sum' => 0.0];
            foreach ($evaluation as $row) {
                $result = (new NflSpreadBacktestEvaluator)->evaluate($row['model_margin'], $row['home_line'], $row['actual_margin']);
                $model[match ($result['result']) {
                    'win' => 'wins', 'loss' => 'losses', 'push' => 'pushes', default => 'no_pick',
                }]++;
                $model['model_absolute_error_sum'] += abs($row['actual_margin'] - $row['model_margin']);
                $model['market_absolute_error_sum'] += abs($row['actual_margin'] + $row['home_line']);
                $estimate = (new NflSpreadProbability)->estimate($residuals, $row['model_margin'], $row['home_line']);
                if ($estimate['conditional_cover_probability'] !== null && in_array($result['result'], ['win', 'loss'], true)) {
                    $model['probability_games']++;
                    $model['brier_sum'] += ($estimate['conditional_cover_probability'] - ($result['won'] ? 1 : 0)) ** 2;
                }
            }
            $count = $model['evaluation_games'];
            $model['model_mae'] = $count ? round($model['model_absolute_error_sum'] / $count, 4) : null;
            $model['market_mae'] = $count ? round($model['market_absolute_error_sum'] / $count, 4) : null;
            $model['conditional_cover_brier'] = $model['probability_games'] ? round($model['brier_sum'] / $model['probability_games'], 6) : null;
            $model['coin_flip_brier'] = $model['probability_games'] ? 0.25 : null;
            unset($model['model_absolute_error_sum'], $model['market_absolute_error_sum'], $model['brier_sum']);
            $report['models'][$version] = $model;
        }

        return $report;
    }
}
