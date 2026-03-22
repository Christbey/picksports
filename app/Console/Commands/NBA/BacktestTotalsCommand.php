<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\GeneratePrediction;
use App\Models\NBA\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestTotalsCommand extends Command
{
    protected $signature = 'nba:backtest-totals
        {--season= : Filter by season}
        {--limit=250 : Limit number of most recent graded/final predictions to inspect}
        {--stored : Use stored predicted totals instead of recomputing with the current model}
        {--detailed : Show biggest misses}';

    protected $description = 'Backtest NBA total predictions against market totals and final scores';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No final NBA predictions with totals market data found for the selected scope.');

            return self::SUCCESS;
        }

        $summary = $this->summarize($rows);

        $this->info('NBA Totals Backtest');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows: '.$summary['count']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Avg model total', number_format($summary['avg_model_total'], 1)],
                ['Avg market total', number_format($summary['avg_market_total'], 1)],
                ['Avg actual total', number_format($summary['avg_actual_total'], 1)],
                ['Model bias vs market', $this->signed($summary['avg_model_total'] - $summary['avg_market_total'], 1)],
                ['Model bias vs actual', $this->signed($summary['avg_model_total'] - $summary['avg_actual_total'], 1)],
                ['Market MAE', number_format($summary['market_mae'], 2)],
                ['Model MAE', number_format($summary['model_mae'], 2)],
                ['O/U record', "{$summary['wins']}-{$summary['losses']}-{$summary['pushes']}"],
                ['O/U win rate', number_format($summary['win_rate'], 1).'%'],
                ['Under picks', "{$summary['under_picks']} (".number_format($summary['under_pick_rate'], 1).'%)'],
                ['Over picks', "{$summary['over_picks']} (".number_format($summary['over_pick_rate'], 1).'%)'],
            ]
        );

        $this->newLine();
        $this->info('Bias By Market Bucket');
        $this->table(
            ['Bucket', 'Games', 'Avg Model', 'Avg Market', 'Avg Actual', 'Bias vs Market', 'Win Rate'],
            $summary['buckets']
        );

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Biggest Misses');
            $this->table(
                ['Game', 'Model', 'Market', 'Actual', 'Pick', 'Result', 'Model Error'],
                $summary['biggest_misses']
            );
        }

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $generator = app(GeneratePrediction::class);

        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_total')
            ->whereHas('game', function ($query) {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($this->option('season')) {
                    $query->where('season', (int) $this->option('season'));
                }
            })
            ->latest();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(function (Prediction $prediction) use ($generator): ?array {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                $marketTotal = $this->extractMarketTotal($game->odds_data);
                if ($marketTotal === null) {
                    return null;
                }

                $actualTotal = (float) $game->home_score + (float) $game->away_score;
                $modelTotal = $this->option('stored')
                    ? (float) $prediction->predicted_total
                    : $this->recomputedTotal($generator, $game, $prediction);
                $pick = $modelTotal > $marketTotal ? 'over' : 'under';
                $result = $actualTotal > $marketTotal
                    ? 'over'
                    : ($actualTotal < $marketTotal ? 'under' : 'push');

                return [
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'model_total' => $modelTotal,
                    'market_total' => $marketTotal,
                    'actual_total' => $actualTotal,
                    'pick' => $pick,
                    'result' => $result,
                    'won' => $pick === $result,
                    'push' => $result === 'push',
                    'edge' => abs($modelTotal - $marketTotal),
                    'model_error' => abs($actualTotal - $modelTotal),
                ];
            })
            ->filter()
            ->values();
    }

    private function extractMarketTotal(mixed $oddsData): ?float
    {
        if (! is_array($oddsData)) {
            return null;
        }

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'totals') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === 'Over' && is_numeric($outcome['point'] ?? null)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    private function recomputedTotal(GeneratePrediction $generator, object $game, Prediction $prediction): float
    {
        $simulatedGame = clone $game;
        $simulatedGame->status = 'STATUS_SCHEDULED';

        $preview = $generator->preview($simulatedGame);

        return (float) ($preview['predicted_total'] ?? $prediction->predicted_total);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows): array
    {
        $threshold = (float) config('nba.betting.edge_thresholds.total');
        $recommended = $rows->filter(fn (array $row) => (float) $row['edge'] >= $threshold)->values();
        $wins = $recommended->where('won', true)->count();
        $pushes = $recommended->where('push', true)->count();
        $losses = $recommended->count() - $wins - $pushes;
        $underPicks = $recommended->where('pick', 'under')->count();
        $overPicks = $recommended->where('pick', 'over')->count();

        return [
            'count' => $rows->count(),
            'wins' => $wins,
            'losses' => $losses,
            'pushes' => $pushes,
            'win_rate' => ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0,
            'under_picks' => $underPicks,
            'over_picks' => $overPicks,
            'under_pick_rate' => $recommended->count() > 0 ? ($underPicks / $recommended->count()) * 100 : 0.0,
            'over_pick_rate' => $recommended->count() > 0 ? ($overPicks / $recommended->count()) * 100 : 0.0,
            'avg_model_total' => (float) $rows->avg('model_total'),
            'avg_market_total' => (float) $rows->avg('market_total'),
            'avg_actual_total' => (float) $rows->avg('actual_total'),
            'model_mae' => (float) $rows->avg('model_error'),
            'market_mae' => (float) $rows->map(fn (array $row) => abs($row['actual_total'] - $row['market_total']))->avg(),
            'buckets' => $this->bucketRows($recommended),
            'biggest_misses' => $recommended
                ->sortByDesc('model_error')
                ->take(10)
                ->map(fn (array $row) => [
                    $row['game'],
                    number_format((float) $row['model_total'], 1),
                    number_format((float) $row['market_total'], 1),
                    number_format((float) $row['actual_total'], 1),
                    strtoupper((string) $row['pick']),
                    strtoupper((string) $row['result']),
                    number_format((float) $row['model_error'], 1),
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function bucketRows(Collection $rows): array
    {
        $buckets = [
            '<220' => fn (float $market) => $market < 220.0,
            '220-229.9' => fn (float $market) => $market >= 220.0 && $market < 230.0,
            '230-239.9' => fn (float $market) => $market >= 230.0 && $market < 240.0,
            '240+' => fn (float $market) => $market >= 240.0,
        ];

        $table = [];
        foreach ($buckets as $label => $filter) {
            $group = $rows->filter(fn (array $row) => $filter((float) $row['market_total']))->values();
            if ($group->isEmpty()) {
                continue;
            }

            $wins = $group->where('won', true)->count();
            $losses = $group->where('push', false)->count() - $wins;
            $winRate = ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0;

            $table[] = [
                $label,
                (string) $group->count(),
                number_format((float) $group->avg('model_total'), 1),
                number_format((float) $group->avg('market_total'), 1),
                number_format((float) $group->avg('actual_total'), 1),
                $this->signed((float) $group->avg('model_total') - (float) $group->avg('market_total'), 1),
                number_format($winRate, 1).'%',
            ];
        }

        return $table;
    }

    private function teamName(mixed $team): string
    {
        return (string) ($team->abbreviation ?? $team->location ?? 'UNK');
    }

    private function signed(float $value, int $precision = 1): string
    {
        $formatted = number_format($value, $precision);

        return $value > 0 ? "+{$formatted}" : $formatted;
    }
}
