<?php

namespace App\Console\Commands\MLB;

use App\Models\BetDecision;
use App\Models\MLB\Game;
use App\Models\ShadowModelOutput;
use App\Support\MLB\MlbLineScores;
use Illuminate\Console\Command;

class ReportPeriodModelPerformanceCommand extends Command
{
    protected $signature = 'mlb:report-period-model-performance
        {--artifact= : Restrict the report to one artifact UUID}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report MLB F3/F5 shadow calibration, ROI, CLV, ties, and probability buckets';

    public function handle(): int
    {
        $markets = ['first_3_moneyline' => 3, 'first_5_moneyline' => 5];
        $outputs = ShadowModelOutput::query()
            ->where('sport', 'mlb')
            ->whereIn('market_type', array_keys($markets))
            ->when($this->option('artifact'), fn ($query) => $query
                ->where('model_artifact_id', $this->option('artifact')))
            ->get();
        $games = Game::query()
            ->whereIn('id', $outputs->pluck('game_id')->unique())
            ->where('status', config('mlb.statuses.final', 'STATUS_FINAL'))
            ->get()
            ->keyBy('id');
        $decisions = BetDecision::query()
            ->with('settlement')
            ->where('sport', 'mlb')
            ->whereIn('market_type', array_keys($markets))
            ->when($this->option('artifact'), fn ($query) => $query
                ->where('model_artifact_id', $this->option('artifact')))
            ->get()
            ->groupBy('market_type');

        $report = [];
        foreach ($markets as $market => $innings) {
            $graded = [];
            foreach ($outputs->where('market_type', $market) as $output) {
                $game = $games->get($output->game_id);
                $probabilities = $this->probabilities($output);
                $target = $game ? $this->target($game, $innings) : null;
                if ($probabilities === null || $target === null) {
                    continue;
                }
                $graded[] = [
                    'probabilities' => $probabilities,
                    'target' => $target,
                    'confidence' => max($probabilities),
                    'correct' => array_search(max($probabilities), $probabilities, true) === $target,
                ];
            }

            $marketDecisions = $decisions->get($market, collect())
                ->filter(fn (BetDecision $decision): bool => $decision->settlement !== null);
            $profits = $marketDecisions
                ->map(fn (BetDecision $decision): ?float => is_numeric(
                    data_get($decision->settlement?->metadata, 'shadow_profit_units'),
                ) ? (float) data_get(
                    $decision->settlement?->metadata,
                    'shadow_profit_units',
                ) : null)
                ->filter(fn (?float $profit): bool => $profit !== null);
            $clv = $marketDecisions
                ->pluck('settlement.clv')
                ->filter(fn (mixed $value): bool => is_numeric($value))
                ->map(fn (mixed $value): float => (float) $value);

            $report[$market] = [
                'shadow_predictions' => $outputs->where('market_type', $market)->count(),
                'graded_predictions' => count($graded),
                'accuracy' => $this->average(array_map(
                    fn (array $row): float => $row['correct'] ? 1.0 : 0.0,
                    $graded,
                )),
                'multiclass_brier' => $this->average(array_map(
                    function (array $row): float {
                        return array_sum(array_map(
                            fn (float $probability, int $class): float => (
                                $probability - ($row['target'] === $class ? 1.0 : 0.0)
                            ) ** 2,
                            $row['probabilities'],
                            [0, 1, 2],
                        ));
                    },
                    $graded,
                )),
                'log_loss' => $this->average(array_map(
                    fn (array $row): float => -log(max(
                        0.000001,
                        $row['probabilities'][$row['target']],
                    )),
                    $graded,
                )),
                'tie_rate' => $this->average(array_map(
                    fn (array $row): float => $row['target'] === 1 ? 1.0 : 0.0,
                    $graded,
                )),
                'mean_tie_probability' => $this->average(array_map(
                    fn (array $row): float => $row['probabilities'][1],
                    $graded,
                )),
                'settled_quote_decisions' => $marketDecisions->count(),
                'qualified_tracking_bets' => $marketDecisions->where('is_bet', true)->count(),
                'counterfactual_roi' => $profits->isEmpty() ? null : round($profits->avg(), 6),
                'average_clv' => $clv->isEmpty() ? null : round($clv->avg(), 6),
                'probability_buckets' => $this->buckets($graded),
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($report as $market => $metrics) {
            $this->newLine();
            $this->info(strtoupper(str_replace('_', ' ', $market)));
            $this->table(
                ['Metric', 'Value'],
                collect($metrics)
                    ->except('probability_buckets')
                    ->map(fn (mixed $value, string $key): array => [
                        str_replace('_', ' ', $key),
                        is_float($value) ? number_format($value, 6) : ($value ?? 'n/a'),
                    ])
                    ->values()
                    ->all(),
            );
            $this->table(
                ['Bucket', 'Count', 'Mean confidence', 'Accuracy'],
                collect($metrics['probability_buckets'])->map(fn (array $bucket): array => [
                    $bucket['bucket'],
                    $bucket['count'],
                    number_format($bucket['mean_confidence'], 4),
                    number_format($bucket['accuracy'], 4),
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function probabilities(ShadowModelOutput $output): ?array
    {
        $probabilities = [
            $this->probability(data_get($output->explanation, 'challenger_outputs.away_win_probability')),
            $this->probability(data_get($output->explanation, 'challenger_outputs.tie_probability')),
            $this->probability(data_get($output->explanation, 'challenger_outputs.home_win_probability')),
        ];

        return in_array(null, $probabilities, true) ? null : $probabilities;
    }

    private function target(Game $game, int $innings): ?int
    {
        $home = array_slice(MlbLineScores::normalize($game->home_linescores), 0, $innings);
        $away = array_slice(MlbLineScores::normalize($game->away_linescores), 0, $innings);
        if (count($home) < $innings
            || count($away) < $innings
            || collect([...$home, ...$away])->contains(
                fn (mixed $score): bool => ! is_numeric($score),
            )) {
            return null;
        }

        $margin = array_sum(array_map('floatval', $home))
            - array_sum(array_map('floatval', $away));

        return $margin > 0 ? 2 : ($margin < 0 ? 0 : 1);
    }

    private function probability(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1
            ? (float) $value
            : null;
    }

    /**
     * @param  list<float>  $values
     */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 6);
    }

    /**
     * @param  list<array{probabilities: array<int, float>, target: int, confidence: float, correct: bool}>  $graded
     * @return list<array{bucket: string, count: int, mean_confidence: float, accuracy: float}>
     */
    private function buckets(array $graded): array
    {
        $buckets = [];
        foreach ([[0.0, 0.4], [0.4, 0.5], [0.5, 0.6], [0.6, 0.7], [0.7, 1.01]] as [$low, $high]) {
            $rows = array_values(array_filter(
                $graded,
                fn (array $row): bool => $row['confidence'] >= $low
                    && $row['confidence'] < $high,
            ));
            if ($rows === []) {
                continue;
            }
            $buckets[] = [
                'bucket' => sprintf('%.1f-%.1f', $low, min(1.0, $high)),
                'count' => count($rows),
                'mean_confidence' => $this->average(array_column($rows, 'confidence')) ?? 0.0,
                'accuracy' => $this->average(array_map(
                    fn (array $row): float => $row['correct'] ? 1.0 : 0.0,
                    $rows,
                )) ?? 0.0,
            ];
        }

        return $buckets;
    }
}
