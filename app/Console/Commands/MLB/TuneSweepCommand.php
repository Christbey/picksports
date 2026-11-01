<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Prediction;
use App\Services\MLB\MlbTuningReplaySource;
use App\Services\MLB\Predictions\MlbCalculationReleaseDefinition;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class TuneSweepCommand extends Command
{
    protected $signature = 'mlb:tune-sweep
        {--forecast-source= : canonical or legacy (defaults to the active MLB evidence source)}
        {--json : Output structured JSON in canonical mode}
        {--limit=2500 : Maximum eligible canonical forecasts to inspect}
        {--average-total= : Comma-separated canonical total.average_total values to replay}
        {--season=2026 : Season to evaluate against}
        {--hfa= : Comma-separated HFA Elo values to sweep (e.g. 0,10,15,20,25,35)}
        {--base-runs= : Comma-separated base_runs values to sweep}
        {--coef= : Comma-separated spread_to_probability_coefficient values to sweep}
        {--feature-version= : Filter to a single feature_version (use "any" to include all)}
        {--baseline-hfa=35 : HFA the stored predictions were generated under (legacy default 35)}
        {--baseline-base-runs=9.7 : base_runs the stored predictions were generated under}
        {--output= : Optional JSON path}';

    protected $description = 'Re-grade MLB predictions under hypothetical HFA / base_runs / coefficient values without writing changes.';

    public function handle(): int
    {
        $source = $this->option('forecast-source') ?? ((config('prediction_lifecycle.canonical_pipeline.mlb') || config('prediction_lifecycle.canonical_reads.mlb')) ? 'canonical' : 'legacy');
        if (! in_array($source, ['canonical', 'legacy'], true)) {
            $this->error('Forecast source must be canonical or legacy.');

            return self::FAILURE;
        }
        if ($source === 'canonical') {
            return $this->canonicalSweep();
        }
        if ($this->option('average-total') !== null || $this->option('json')) {
            $this->error('--average-total and --json require canonical mode.');

            return self::FAILURE;
        }
        $season = (int) $this->option('season');
        $currentPredictionHfa = (float) config(
            'mlb.prediction.home_field_advantage',
            config('mlb.elo.home_field_advantage')
        );
        $hfaValues = $this->parseFloatList($this->option('hfa'), [$currentPredictionHfa]);
        $baseRunsValues = $this->parseFloatList($this->option('base-runs'), [(float) config('mlb.prediction.total_model.base_runs')]);
        $coefValues = $this->parseFloatList($this->option('coef'), [(float) config('mlb.prediction.spread_to_probability_coefficient')]);

        $divisor = (float) config('mlb.prediction.elo_diff_to_spread_divisor', 44.0);
        // Stored predictions were generated under the legacy Elo HFA of 35 (pre-split).
        // For accurate linear deltas we anchor to that legacy value, not the current prediction HFA.
        $oldHfa = (float) $this->option('baseline-hfa') ?: 35.0;
        $oldBaseRuns = (float) ($this->option('baseline-base-runs') ?: 9.7);

        $query = Prediction::query()
            ->where('season', $season)
            ->whereNotNull('graded_at')
            ->whereNotNull('home_combined_elo')
            ->whereNotNull('away_combined_elo');

        $featureVersion = (string) ($this->option('feature-version') ?? 'core-v3');
        if ($featureVersion !== '' && strtolower($featureVersion) !== 'any') {
            $query->where('feature_version', $featureVersion);
        }

        $rows = $query->get([
            'predicted_spread', 'predicted_total', 'win_probability',
            'home_combined_elo', 'away_combined_elo',
            'actual_spread', 'actual_total', 'winner_correct',
            'feature_version',
        ]);

        if ($rows->isEmpty()) {
            $this->warn('No graded predictions matched the filters.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Sweeping HFA=%s base_runs=%s coef=%s across %d predictions (season %d)',
            implode(',', $hfaValues),
            implode(',', $baseRunsValues),
            implode(',', $coefValues),
            $rows->count(),
            $season
        ));

        $results = [];
        foreach ($hfaValues as $hfa) {
            foreach ($baseRunsValues as $baseRuns) {
                foreach ($coefValues as $coef) {
                    $results[] = $this->evaluate($rows, $hfa, $oldHfa, $divisor, $baseRuns, $oldBaseRuns, $coef);
                }
            }
        }

        $rowsTable = array_map(function (array $r) {
            return [
                $this->fmt($r['hfa']),
                $this->fmt($r['base_runs']),
                $this->fmt($r['coef']),
                number_format($r['win_pct'] * 100, 2).'%',
                number_format($r['home_pick_pct'] * 100, 1).'%',
                number_format($r['spread_mae'], 3),
                $this->signed($r['spread_bias'], 3),
                number_format($r['total_mae'], 3),
                $this->signed($r['total_bias'], 3),
                number_format($r['brier'], 4),
                number_format($r['avg_confidence'], 2),
            ];
        }, $results);

        $this->table(
            ['HFA', 'base_runs', 'coef', 'Win %', 'Home pick %', 'sp MAE', 'sp bias', 'tot MAE', 'tot bias', 'Brier', 'avg conf'],
            $rowsTable
        );

        $this->newLine();
        $best = $this->pickBest($results);
        $this->line('Top picks (lowest combined error w/ accuracy weighting):');
        foreach ($best as $label => $row) {
            $this->line(sprintf(
                '  %-16s HFA=%s base_runs=%s coef=%s -> win=%.2f%% sp_MAE=%.3f tot_MAE=%.3f Brier=%.4f',
                "[{$label}]",
                $this->fmt($row['hfa']),
                $this->fmt($row['base_runs']),
                $this->fmt($row['coef']),
                $row['win_pct'] * 100,
                $row['spread_mae'],
                $row['total_mae'],
                $row['brier']
            ));
        }

        if ($output = $this->option('output')) {
            $path = (string) $output;
            $dir = dirname($path);
            if (! is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            File::put($path, json_encode([
                'season' => $season,
                'sample' => $rows->count(),
                'baseline' => [
                    'hfa' => $oldHfa,
                    'base_runs' => $oldBaseRuns,
                    'coef' => (float) config('mlb.prediction.spread_to_probability_coefficient'),
                    'divisor' => $divisor,
                ],
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Wrote report to {$path}");
        }

        return self::SUCCESS;
    }

    private function canonicalSweep(): int
    {
        foreach (['base-runs', 'baseline-hfa', 'baseline-base-runs'] as $option) {
            if ($this->input->hasParameterOption('--'.$option)) {
                $this->error('--'.$option.' is a legacy-only linear baseline option; canonical tuning replays frozen release configuration. Use --average-total for the canonical total model.');

                return self::FAILURE;
            }
        }
        $grids = [];
        foreach (['hfa', 'average-total', 'coef'] as $option) {
            $value = $this->option($option);
            $grids[$option] = [null];
            if ($value === null) {
                continue;
            }
            $parts = explode(',', (string) $value);
            foreach ($parts as $part) {
                if (! is_numeric(trim($part)) || ! is_finite((float) $part) || ($option !== 'hfa' && (float) $part <= 0)) {
                    $this->error('Canonical '.$option.' values must be finite'.($option === 'hfa' ? '.' : ' and positive.'));

                    return self::FAILURE;
                }
            }
            $grids[$option] = array_values(array_unique(array_map(fn ($part) => (float) trim($part), $parts)));
        }
        $version = (string) ($this->option('feature-version') ?? MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION);
        $source = app(MlbTuningReplaySource::class);
        $corpus = $source->rows((int) $this->option('season'), $version === '' || strtolower($version) === 'any' ? null : $version, max(1, (int) $this->option('limit')));
        $results = [];
        foreach ($grids['hfa'] as $hfa) {
            foreach ($grids['average-total'] as $total) {
                foreach ($grids['coef'] as $coef) {
                    $metrics = $this->score($corpus['rows'], fn ($row) => $source->project($row, $hfa, $total, $coef));
                    if ($metrics['n'] === 0) {
                        foreach (array_keys($metrics) as $key) {
                            if ($key !== 'n') {
                                $metrics[$key] = null;
                            }
                        }
                    }
                    $results[] = ['hfa' => $hfa, 'average_total' => $total, 'coef' => $coef, ...$metrics];
                }
            }
        }
        $report = ['forecast_source' => 'canonical', 'season' => (int) $this->option('season'), 'input_schema' => $version,
            'sample' => $corpus['rows']->count(), 'excluded' => $corpus['excluded'],
            'parameter_defaults' => "Null parameters retain each forecast's frozen release value.",
            'approval_notice' => 'Read-only counterfactual research; grants no calibration, promotion or betting approval.',
            'canonical_evidence' => $corpus['rows']->map(fn ($row) => $row->lineage)->all(), 'results' => $results];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('MLB canonical tuning replay: '.$report['sample'].' forecasts');
            $this->line($report['parameter_defaults']);
            $this->line($report['approval_notice']);
            foreach ($corpus['excluded'] as $reason => $count) {
                $this->warn($reason.': '.$count);
            }
            if ($corpus['rows']->isEmpty()) {
                $this->warn('No replayable canonical forecasts matched the filters.');
            } else {
                $this->table(['HFA', 'average_total', 'coef', 'Rows', 'Win %', 'Spread MAE', 'Total MAE', 'Brier'], array_map(fn ($r) => [
                    $r['hfa'] ?? 'recorded', $r['average_total'] ?? 'recorded', $r['coef'] ?? 'recorded', $r['n'],
                    $r['n'] ? number_format($r['win_pct'] * 100, 2) : 'n/a', $r['n'] ? number_format($r['spread_mae'], 3) : 'n/a', $r['n'] ? number_format($r['total_mae'], 3) : 'n/a', $r['n'] ? number_format($r['brier'], 4) : 'n/a',
                ], $results));
            }
        }
        if ($output = $this->option('output')) {
            File::ensureDirectoryExists(dirname((string) $output));
            File::put((string) $output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Prediction>  $rows
     * @return array<string, mixed>
     */
    private function evaluate(
        Collection $rows,
        float $hfa,
        float $oldHfa,
        float $divisor,
        float $baseRuns,
        float $oldBaseRuns,
        float $coef
    ): array {
        $hfaDelta = ($hfa - $oldHfa) / $divisor;
        $totalDelta = $baseRuns - $oldBaseRuns;

        return ['hfa' => $hfa, 'base_runs' => $baseRuns, 'coef' => $coef, ...$this->score($rows, function ($row) use ($hfaDelta, $totalDelta, $coef): array {
            $spread = (float) $row->predicted_spread + $hfaDelta;

            return ['spread' => $spread, 'total' => (float) $row->predicted_total + $totalDelta,
                'probability' => 1.0 / (1.0 + exp(-$spread / max($coef, 0.0001)))];
        })];
    }

    private function score(Collection $rows, callable $project): array
    {
        $n = 0;
        $winnerCorrect = 0;
        $homePicks = 0;
        $spreadErrSum = 0.0;
        $spreadBiasSum = 0.0;
        $totalErrSum = 0.0;
        $totalBiasSum = 0.0;
        $brierSum = 0.0;
        $confSum = 0.0;

        foreach ($rows as $row) {
            $projected = $project($row);
            $newSpread = $projected['spread'];
            $newTotal = $projected['total'];
            $actualSpread = (float) $row->actual_spread;
            $actualTotal = (float) $row->actual_total;

            $pHome = $projected['probability'];
            $pickHome = $newSpread > 0;
            if ($newSpread === 0.0) {
                continue;
            }
            $homeWon = $actualSpread > 0;
            $newWinnerCorrect = ($pickHome === $homeWon) ? 1 : 0;
            $pPick = $pickHome ? $pHome : (1.0 - $pHome);
            $confidence = max($pHome, 1.0 - $pHome) * 100;

            $n++;
            $winnerCorrect += $newWinnerCorrect;
            $homePicks += $pickHome ? 1 : 0;
            $spreadErrSum += abs($newSpread - $actualSpread);
            $spreadBiasSum += $newSpread - $actualSpread;
            $totalErrSum += abs($newTotal - $actualTotal);
            $totalBiasSum += $newTotal - $actualTotal;
            $brierSum += ($pPick - $newWinnerCorrect) ** 2;
            $confSum += $confidence;
        }

        return [
            'n' => $n,
            'win_pct' => $n ? $winnerCorrect / $n : 0.0,
            'home_pick_pct' => $n ? $homePicks / $n : 0.0,
            'spread_mae' => $n ? $spreadErrSum / $n : 0.0,
            'spread_bias' => $n ? $spreadBiasSum / $n : 0.0,
            'total_mae' => $n ? $totalErrSum / $n : 0.0,
            'total_bias' => $n ? $totalBiasSum / $n : 0.0,
            'brier' => $n ? $brierSum / $n : 0.0,
            'avg_confidence' => $n ? $confSum / $n : 0.0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, array<string, mixed>>
     */
    private function pickBest(array $results): array
    {
        $byWin = collect($results)->sortByDesc('win_pct')->first();
        $bySpread = collect($results)->sortBy('spread_mae')->first();
        $byTotal = collect($results)->sortBy('total_mae')->first();
        $byBrier = collect($results)->sortBy('brier')->first();
        $byCombined = collect($results)->sortBy(function ($r) {
            return -$r['win_pct'] + ($r['spread_mae'] / 5.0) + ($r['total_mae'] / 5.0) + ($r['brier'] * 2.0);
        })->first();

        return array_filter([
            'best_win' => $byWin,
            'best_spread' => $bySpread,
            'best_total' => $byTotal,
            'best_brier' => $byBrier,
            'combined' => $byCombined,
        ]);
    }

    /**
     * @param  array<int, float>  $default
     * @return array<int, float>
     */
    private function parseFloatList(?string $value, array $default): array
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return collect(explode(',', $value))
            ->map(fn ($v) => (float) trim($v))
            ->filter(fn ($v) => is_finite($v))
            ->values()
            ->all();
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function signed(float $v, int $decimals): string
    {
        return ($v >= 0 ? '+' : '').number_format($v, $decimals);
    }
}
