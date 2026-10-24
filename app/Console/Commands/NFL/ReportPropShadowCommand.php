<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflPropShadowService;
use Illuminate\Console\Command;

class ReportPropShadowCommand extends Command
{
    protected $signature = 'nfl:report-prop-shadow {--season= : Required NFL season}';

    protected $description = 'Report paired prospective NFL prop errors by frozen experiment and market';

    public function handle(): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2000) {
            $this->error('Supply a valid --season.');

            return self::FAILURE;
        }
        $groups = [];
        PredictionFeatureSnapshot::query()->where('blend_version', NflPropShadowService::POLICY)
            ->whereIn('game_id', Game::query()->select('id')->where('season', $season))
            ->chunkById(100, function ($snapshots) use (&$groups): void {
                $evaluations = PredictionEvaluation::query()->where('prediction_table', 'prediction_feature_snapshots')
                    ->where('blend_version', NflPropShadowService::POLICY)->whereIn('prediction_id', $snapshots->pluck('id'))->get()->keyBy('prediction_id');
                foreach ($snapshots as $snapshot) {
                    $key = $snapshot->model_metadata['bundle_sha256'].':'.$snapshot->market_context['market'];
                    $groups[$key] ??= ['bundle_sha256' => $snapshot->model_metadata['bundle_sha256'], 'market' => $snapshot->market_context['market'],
                        'captured' => 0, 'graded' => 0, 'pending' => 0, 'models' => [], 'line_absolute_error' => 0.0];
                    $groups[$key]['captured']++;
                    $evaluation = $evaluations->get($snapshot->id);
                    if (! $evaluation) {
                        $groups[$key]['pending']++;

                        continue;
                    }
                    $groups[$key]['graded']++;
                    $groups[$key]['line_absolute_error'] += $evaluation->market_comparison['line_absolute_error'];
                    foreach ($evaluation->errors as $model => $errors) {
                        foreach ($errors as $metric => $value) {
                            $groups[$key]['models'][$model][$metric] = ($groups[$key]['models'][$model][$metric] ?? 0) + $value;
                        }
                    }
                }
            });
        foreach ($groups as &$group) {
            $n = $group['graded'];
            foreach ($group['models'] as &$metrics) {
                $metrics = ['mae' => $metrics['absolute_error'] / $n, 'rmse' => sqrt($metrics['squared_error'] / $n), 'bias' => $metrics['bias'] / $n];
            }
            unset($metrics);
            $group['line_mae_reference'] = $n ? $group['line_absolute_error'] / $n : null;
            unset($group['line_absolute_error']);
        }
        unset($group);
        $this->line(json_encode(['season' => $season, 'experiments' => array_values($groups),
            'production_promotion' => false, 'probabilities_evaluated' => false], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
