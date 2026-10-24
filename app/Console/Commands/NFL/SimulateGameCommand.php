<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\Simulation\GameSimulationService;
use App\Services\NFL\Simulation\ScoreDistributionSimulator;
use Illuminate\Console\Command;
use Throwable;

class SimulateGameCommand extends Command
{
    protected $signature = 'nfl:simulate-game {game? : Existing NFL game ID}
        {--basis=published : published or pre_market}
        {--iterations= : 1000 to 50000 draws}
        {--seed= : Repeatable nonnegative integer seed}
        {--provisional : Explicit preview-only unfitted assumptions when history is insufficient}
        {--record : Capture the first fitted pregame shadow observation; requires capture_enabled}
        {--grade : Grade mature final shadow observations}
        {--report : Read aggregate shadow evaluation metrics}
        {--replay= : Reproduce a frozen simulation snapshot without writing}
        {--limit=100 : Maximum observations to grade/report, up to 1000}';

    protected $description = 'Preview, freeze, replay and evaluate uncalibrated NFL score-distribution simulations';

    public function handle(GameSimulationService $service, ScoreDistributionSimulator $simulator): int
    {
        try {
            $modes = (int) (bool) $this->option('grade') + (int) (bool) $this->option('report') + (int) ($this->option('replay') !== null);
            if ($modes > 1 || ($modes && ($this->argument('game') !== null || $this->option('record') || $this->option('provisional')))) {
                throw new \InvalidArgumentException('Select exactly one preview, grade, report or replay operation.');
            }
            foreach (['iterations', 'seed', 'limit', 'replay'] as $option) {
                if ($this->option($option) !== null && ! preg_match('/^\d+$/', (string) $this->option($option))) {
                    throw new \InvalidArgumentException('Expected an unsigned integer for --'.$option.'.');
                }
            }
            $limit = (int) $this->option('limit');
            if ($limit < 1 || $limit > 1000) {
                throw new \InvalidArgumentException('Limit must be between 1 and 1000.');
            }
            $query = PredictionFeatureSnapshot::where('sport', 'nfl')->where('blend_version', GameSimulationService::POLICY);
            if ($this->option('report')) {
                $groups = [];
                $query->latest('id')->limit($limit)->get(['id', 'features', 'model_version'])->chunk(100)->each(function ($batch) use (&$groups): void {
                    $evaluations = PredictionEvaluation::where('prediction_table', 'prediction_feature_snapshots')
                        ->where('blend_version', GameSimulationService::POLICY)->whereIn('prediction_id', $batch->pluck('id'))->get()->keyBy('prediction_id');
                    foreach ($batch as $snapshot) {
                        $key = $snapshot->model_version.'|'.$snapshot->features['basis'].'|'.$snapshot->features['source_model_version'];
                        $groups[$key] ??= ['observations' => 0, 'graded' => 0, 'metrics' => [], 'market_brier_sum' => 0, 'market_quotes' => 0, 'market_metrics' => []];
                        $groups[$key]['observations']++;
                        if (! ($evaluation = $evaluations->get($snapshot->id))) {
                            continue;
                        }
                        $groups[$key]['graded']++;
                        foreach ($evaluation->errors as $metric => $value) {
                            $groups[$key]['metrics'][$metric] = ($groups[$key]['metrics'][$metric] ?? 0) + (float) $value;
                        }
                        foreach ($evaluation->market_comparison['markets'] as $market) {
                            $groups[$key]['market_brier_sum'] += $market['brier'];
                            $groups[$key]['market_quotes']++;
                            $kind = $market['market'];
                            $groups[$key]['market_metrics'][$kind] ??= ['quotes' => 0, 'brier' => 0, 'line_absolute_error' => 0, 'actual_push_frequency' => 0, 'predicted_push_frequency' => 0];
                            $metrics = &$groups[$key]['market_metrics'][$kind];
                            $metrics['quotes']++;
                            $metrics['brier'] += $market['brier'];
                            $metrics['line_absolute_error'] += $market['line_absolute_error'];
                            $metrics['actual_push_frequency'] += (int) ($market['actual_outcome'] === 'push');
                            $metrics['predicted_push_frequency'] += $market['frequencies']['push'];
                            unset($metrics);
                        }
                    }
                });
                foreach ($groups as &$group) {
                    foreach ($group['metrics'] as &$value) {
                        $value /= $group['graded'];
                    }
                    unset($value);
                    $group['mean_market_brier'] = $group['market_quotes'] ? $group['market_brier_sum'] / $group['market_quotes'] : null;
                    unset($group['market_brier_sum']);
                    foreach ($group['market_metrics'] as &$metrics) {
                        foreach (['brier', 'line_absolute_error', 'actual_push_frequency', 'predicted_push_frequency'] as $metric) {
                            $metrics[$metric] /= $metrics['quotes'];
                        }
                    }
                    unset($metrics);
                }
                unset($group);
                $result = ['groups' => $groups, 'selection' => 'Newest bounded prospective shadow observations; separate by source version and basis.',
                    'brier_definition' => 'Sum of squared errors over home/away/tie or win/loss/push; range 0 to 2.',
                    'interpretation' => 'Uncalibrated descriptive paired metrics, not profitability or causal uplift; quote counts are not independent game counts.'];
            } elseif ($this->option('grade')) {
                $graded = 0;
                $pending = 0;
                $query->whereNotExists(function ($q): void {
                    $q->selectRaw('1')->from('prediction_evaluations')
                        ->whereColumn('prediction_evaluations.prediction_id', 'prediction_feature_snapshots.id')
                        ->where('prediction_evaluations.prediction_table', 'prediction_feature_snapshots')
                        ->where('prediction_evaluations.blend_version', GameSimulationService::POLICY);
                });
                foreach ($query->orderBy('id')->limit($limit)->get() as $snapshot) {
                    $evaluation = $service->grade($snapshot);
                    $graded += (int) ($evaluation?->wasRecentlyCreated ?? false);
                    $pending += (int) ($evaluation === null);
                }
                $result = ['graded' => $graded, 'pending' => $pending];
            } elseif ($this->option('replay') !== null) {
                $snapshot = $query->findOrFail((int) $this->option('replay'));
                $f = $snapshot->features;
                $output = $simulator->run($f['means']['margin'], $f['means']['total'], $f['history']['pairs'],
                    $snapshot->market_context, $f['iterations'], $f['seed']);
                $result = ['snapshot_id' => $snapshot->id, 'identical' => hash_equals($snapshot->feature_hash, GameSimulationService::fingerprint($f, $output, $snapshot->market_context))];
                if (! $result['identical']) {
                    throw new \RuntimeException('Frozen simulation replay differs from recorded output.');
                }
            } else {
                if (! ctype_digit((string) $this->argument('game')) || ($this->option('record') && $this->option('provisional'))) {
                    throw new \InvalidArgumentException('Provide a game ID; provisional previews cannot be recorded.');
                }
                $game = Game::findOrFail((int) $this->argument('game'));
                $run = $service->preview($game, (string) $this->option('basis'),
                    $this->option('iterations') === null ? null : (int) $this->option('iterations'),
                    $this->option('seed') === null ? null : (int) $this->option('seed'), (bool) $this->option('provisional'));
                if ($this->option('record') && $run['status'] === 'shadow') {
                    $run['recorded_snapshot_id'] = $service->capture($game, $run)->id;
                }
                // Large residual arrays remain in the frozen snapshot, not terminal output.
                $result = collect($run)->except(['features'])->all();
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ($result['status'] ?? null) === 'hold' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
