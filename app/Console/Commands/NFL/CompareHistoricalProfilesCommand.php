<?php

namespace App\Console\Commands\NFL;

use App\Services\ML\CsvDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CompareHistoricalProfilesCommand extends Command
{
    protected $signature = 'nfl:compare-historical-profiles
        {--baseline=storage/app/ml/nfl_elo_only_training_data.csv : Baseline profile CSV}
        {--challenger=storage/app/ml/nfl_full_historical_training_data.csv : Challenger profile CSV}
        {--output=storage/app/ml/reports/nfl_historical_profile_comparison.json : Report path}';

    protected $description = 'Compare two NFL historical profiles on identical games across held-out seasons';

    public function handle(CsvDataset $csv): int
    {
        $baselinePath = $this->absolutePath((string) $this->option('baseline'));
        $challengerPath = $this->absolutePath((string) $this->option('challenger'));
        $outputPath = $this->absolutePath((string) $this->option('output'));
        $baselineRows = collect($csv->read($baselinePath))->keyBy('game_id');
        $challengerRows = collect($csv->read($challengerPath));

        $pairs = $challengerRows
            ->map(function (array $challenger) use ($baselineRows): ?array {
                $baseline = $baselineRows->get($challenger['game_id'] ?? '');
                if (! is_array($baseline)
                    || ($baseline['target_hash'] ?? null) !== ($challenger['target_hash'] ?? null)) {
                    return null;
                }

                return [
                    'season' => (int) ($challenger['season'] ?? 0),
                    'game_id' => (int) ($challenger['game_id'] ?? 0),
                    'target' => (float) ($challenger['target_home_win'] ?? 0),
                    'home_margin' => (float) ($challenger['target_home_margin'] ?? 0),
                    'baseline_probability' => (float) ($baseline['feature_model_win_probability'] ?? 0.5),
                    'challenger_probability' => (float) ($challenger['feature_model_win_probability'] ?? 0.5),
                    'baseline_spread' => $this->numeric($baseline['feature_model_predicted_spread'] ?? null),
                    'challenger_spread' => $this->numeric($challenger['feature_model_predicted_spread'] ?? null),
                ];
            })
            ->filter()
            ->values();

        if ($pairs->isEmpty()) {
            $this->error('No target-identical NFL games were found in both profile datasets.');

            return self::FAILURE;
        }

        $windows = $pairs
            ->groupBy('season')
            ->sortKeys()
            ->map(fn ($seasonPairs, int $season): array => [
                'evaluation_season' => $season,
                ...$this->metrics($seasonPairs->all()),
            ])
            ->values();
        $windowCount = $windows->count();
        $summary = [
            'matched_games' => $pairs->count(),
            'window_count' => $windowCount,
            'challenger_better_window_count' => $windows->filter(
                fn (array $window): bool => $window['brier_delta'] < 0.0
                    && $window['log_loss_delta'] < 0.0
            )->count(),
            'avg_brier_delta' => (float) $windows->avg('brier_delta'),
            'avg_log_loss_delta' => (float) $windows->avg('log_loss_delta'),
            'avg_spread_mae_delta' => (float) $windows->avg('spread_mae_delta'),
        ];

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode([
            'report_type' => 'nfl_historical_profile_rolling_season_comparison',
            'generated_at' => now()->toIso8601String(),
            'baseline' => [
                'path' => $baselinePath,
                'hash' => hash_file('sha256', $baselinePath),
            ],
            'challenger' => [
                'path' => $challengerPath,
                'hash' => hash_file('sha256', $challengerPath),
            ],
            'summary' => $summary,
            'windows' => $windows->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('NFL historical profile rolling-season comparison completed.');
        $this->line("Report: {$outputPath}");
        $this->line('Report SHA-256: '.hash_file('sha256', $outputPath));
        $this->table(
            ['Metric', 'Value'],
            [
                ['Matched games', (string) $summary['matched_games']],
                ['Held-out seasons', (string) $summary['window_count']],
                ['Challenger better seasons', (string) $summary['challenger_better_window_count']],
                ['Average Brier delta', number_format($summary['avg_brier_delta'], 4)],
                ['Average LogLoss delta', number_format($summary['avg_log_loss_delta'], 4)],
                ['Average spread MAE delta', number_format($summary['avg_spread_mae_delta'], 4)],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, int|float|null>>  $pairs
     * @return array<string, int|float>
     */
    private function metrics(array $pairs): array
    {
        $count = max(1, count($pairs));
        $baselineBrier = 0.0;
        $challengerBrier = 0.0;
        $baselineLogLoss = 0.0;
        $challengerLogLoss = 0.0;
        $baselineSpreadError = 0.0;
        $challengerSpreadError = 0.0;
        $spreadCount = 0;

        foreach ($pairs as $pair) {
            $target = (float) $pair['target'];
            $baseline = $this->clip((float) $pair['baseline_probability']);
            $challenger = $this->clip((float) $pair['challenger_probability']);
            $baselineBrier += ($baseline - $target) ** 2;
            $challengerBrier += ($challenger - $target) ** 2;
            $baselineLogLoss += $this->logLoss($baseline, $target);
            $challengerLogLoss += $this->logLoss($challenger, $target);

            if ($pair['baseline_spread'] !== null && $pair['challenger_spread'] !== null) {
                $baselineSpreadError += abs((float) $pair['home_margin'] - (float) $pair['baseline_spread']);
                $challengerSpreadError += abs((float) $pair['home_margin'] - (float) $pair['challenger_spread']);
                $spreadCount++;
            }
        }

        $baselineBrier /= $count;
        $challengerBrier /= $count;
        $baselineLogLoss /= $count;
        $challengerLogLoss /= $count;
        $baselineSpreadMae = $spreadCount > 0 ? $baselineSpreadError / $spreadCount : 0.0;
        $challengerSpreadMae = $spreadCount > 0 ? $challengerSpreadError / $spreadCount : 0.0;

        return [
            'games' => count($pairs),
            'baseline_brier' => $baselineBrier,
            'challenger_brier' => $challengerBrier,
            'brier_delta' => $challengerBrier - $baselineBrier,
            'baseline_log_loss' => $baselineLogLoss,
            'challenger_log_loss' => $challengerLogLoss,
            'log_loss_delta' => $challengerLogLoss - $baselineLogLoss,
            'baseline_spread_mae' => $baselineSpreadMae,
            'challenger_spread_mae' => $challengerSpreadMae,
            'spread_mae_delta' => $challengerSpreadMae - $baselineSpreadMae,
        ];
    }

    private function logLoss(float $probability, float $target): float
    {
        return -(($target * log($probability)) + ((1.0 - $target) * log(1.0 - $probability)));
    }

    private function clip(float $probability): float
    {
        return min(0.999999, max(0.000001, $probability));
    }

    private function numeric(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
