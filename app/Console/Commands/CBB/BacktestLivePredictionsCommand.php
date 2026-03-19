<?php

namespace App\Console\Commands\CBB;

use App\Models\CBB\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestLivePredictionsCommand extends Command
{
    protected $signature = 'cbb:backtest-live-predictions
        {--season= : Filter by season}
        {--limit=25 : Limit detailed current-live rows}
        {--detailed : Show current live prediction rows}';

    protected $description = 'Audit stored CBB live prediction coverage and backtestability';

    public function handle(): int
    {
        $finalRows = $this->loadFinalRows();
        $currentLiveRows = $this->loadCurrentLiveRows();

        $this->info('CBB Live Prediction Audit');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Final predictions with game result', number_format($finalRows['final_predictions'])],
                ['Final rows retaining live fields', number_format($finalRows['final_rows_with_live_fields'])],
                ['Current in-progress rows', number_format($currentLiveRows->count())],
                ['Current in-progress rows with live fields', number_format($currentLiveRows->where('has_live_fields', true)->count())],
                ['Current in-progress rows missing live fields', number_format($currentLiveRows->where('has_live_fields', false)->count())],
            ]
        );

        $this->newLine();

        if ($finalRows['final_rows_with_live_fields'] === 0) {
            $this->warn('Historical live backtesting is not currently possible from stored data.');
            $this->line('Reason: CBB only keeps the latest live fields on the prediction row and clears them when the game is no longer in progress.');
            $this->line('Result: there are no preserved historical live-state snapshots to compare against final outcomes.');
        } else {
            $this->info('Stored Final Live Snapshot Backtest');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Rows', number_format($finalRows['final_rows_with_live_fields'])],
                    ['Live win probability Brier', number_format($finalRows['brier_score'], 4)],
                    ['Live spread MAE', number_format($finalRows['live_spread_mae'], 2)],
                    ['Live total MAE', number_format($finalRows['live_total_mae'], 2)],
                ]
            );
        }

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Current Live Rows');
            $this->table(
                ['Game', 'Status', 'Score', 'Clock', 'Live WP', 'Live Spread', 'Live Total', 'Seconds Left', 'Updated'],
                $currentLiveRows
                    ->take(max(1, (int) $this->option('limit')))
                    ->map(fn (array $row) => [
                        $row['game'],
                        $row['status'],
                        $row['score'],
                        $row['clock'],
                        $row['live_win_probability'],
                        $row['live_predicted_spread'],
                        $row['live_predicted_total'],
                        $row['live_seconds_remaining'],
                        $row['live_updated_at'],
                    ])
                    ->all()
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, float|int>
     */
    private function loadFinalRows(): array
    {
        $query = Prediction::query()
            ->with('game')
            ->whereHas('game', function ($query): void {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($this->option('season')) {
                    $query->where('season', (int) $this->option('season'));
                }
            });

        $rows = $query->get();
        $finalPredictions = $rows->count();

        $snapshots = $rows->filter(function (Prediction $prediction): bool {
            return $prediction->live_win_probability !== null
                || $prediction->live_predicted_spread !== null
                || $prediction->live_predicted_total !== null;
        })->values();

        if ($snapshots->isEmpty()) {
            return [
                'final_predictions' => $finalPredictions,
                'final_rows_with_live_fields' => 0,
                'brier_score' => 0.0,
                'live_spread_mae' => 0.0,
                'live_total_mae' => 0.0,
            ];
        }

        $brier = $snapshots->map(function (Prediction $prediction): float {
            $actual = (($prediction->game->home_score ?? 0) - ($prediction->game->away_score ?? 0)) > 0 ? 1.0 : 0.0;
            $predicted = (float) $prediction->live_win_probability;

            return ($predicted - $actual) ** 2;
        })->avg() ?? 0.0;

        $spreadMae = $snapshots->map(function (Prediction $prediction): float {
            $actualMargin = (float) $prediction->game->home_score - (float) $prediction->game->away_score;

            return abs((float) $prediction->live_predicted_spread - $actualMargin);
        })->avg() ?? 0.0;

        $totalMae = $snapshots->map(function (Prediction $prediction): float {
            $actualTotal = (float) $prediction->game->home_score + (float) $prediction->game->away_score;

            return abs((float) $prediction->live_predicted_total - $actualTotal);
        })->avg() ?? 0.0;

        return [
            'final_predictions' => $finalPredictions,
            'final_rows_with_live_fields' => $snapshots->count(),
            'brier_score' => $brier,
            'live_spread_mae' => $spreadMae,
            'live_total_mae' => $totalMae,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadCurrentLiveRows(): Collection
    {
        return Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query): void {
                $query->whereIn('status', [
                    'STATUS_IN_PROGRESS',
                    'STATUS_HALFTIME',
                    'STATUS_END_PERIOD',
                    'STATUS_SUSPENDED',
                ]);

                if ($this->option('season')) {
                    $query->where('season', (int) $this->option('season'));
                }
            })
            ->latest('live_updated_at')
            ->get()
            ->map(function (Prediction $prediction): array {
                $game = $prediction->game;

                return [
                    'game' => ($game->awayTeam?->abbreviation ?? '?').' @ '.($game->homeTeam?->abbreviation ?? '?'),
                    'status' => (string) $game->status,
                    'score' => ($game->home_score ?? 0).'-'.($game->away_score ?? 0),
                    'clock' => trim((string) (($game->period ? 'P'.$game->period.' ' : '').($game->game_clock ?? ''))),
                    'has_live_fields' => $prediction->live_win_probability !== null
                        || $prediction->live_predicted_spread !== null
                        || $prediction->live_predicted_total !== null,
                    'live_win_probability' => $prediction->live_win_probability !== null ? number_format((float) $prediction->live_win_probability * 100, 1).'%' : '-',
                    'live_predicted_spread' => $prediction->live_predicted_spread !== null ? number_format((float) $prediction->live_predicted_spread, 1) : '-',
                    'live_predicted_total' => $prediction->live_predicted_total !== null ? number_format((float) $prediction->live_predicted_total, 1) : '-',
                    'live_seconds_remaining' => $prediction->live_seconds_remaining ?? '-',
                    'live_updated_at' => $prediction->live_updated_at?->toDateTimeString() ?? '-',
                ];
            })
            ->values();
    }
}
