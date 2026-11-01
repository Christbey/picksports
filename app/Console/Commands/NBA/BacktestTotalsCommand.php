<?php

namespace App\Console\Commands\NBA;

use App\Models\NBA\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\Basketball\BasketballBacktestForecastSource;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestTotalsCommand extends Command
{
    protected $signature = 'nba:backtest-totals
        {--forecast-source= : canonical or legacy (defaults to the active NBA source)}
        {--season= : Filter by season}
        {--limit=250 : Limit number of most recent graded/final predictions to inspect}
        {--stored : Compatibility flag; evaluation always uses frozen pregame evidence}
        {--season-type=2 : Restrict to a season phase (2 regular, 3 postseason)}
        {--detailed : Show biggest misses}';

    protected $description = 'Backtest NBA total predictions against market totals and final scores';

    private int $excludedGames = 0;

    public function handle(): int
    {
        try {
            $source = app(BasketballBacktestForecastSource::class)->name('nba', $this->option('forecast-source'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $rows = $source === 'canonical' ? $this->canonicalRows() : $this->loadRows();
        $this->line('Forecast source: '.$source);

        $this->line('Source: frozen pregame forecasts and matching frozen market totals.');
        $this->line('Excluded games without usable pregame evidence: '.$this->excludedGames);
        if ($rows->isEmpty()) {
            $this->warn('No final NBA games with usable frozen pregame totals found for the selected scope.');

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

    private function canonicalRows(): Collection
    {
        $season = $this->option('season') ? (int) $this->option('season') : null;
        $seasonType = (int) $this->option('season-type');
        $forecasts = app(BasketballBacktestForecastSource::class)->forFinalGames('nba', $season, $seasonType);
        $usable = $forecasts->filter(fn (object $forecast): bool => $forecast->predicted_total !== null && $forecast->market_total !== null);
        $this->excludedGames = Game::where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->where('season_type', $seasonType)->when($season !== null, fn ($query) => $query->where('season', $season))->count() - $usable->count();
        $rows = $usable->sortByDesc(fn (object $forecast): array => [$forecast->game_start_at->timestamp, $forecast->game->id])
            ->map(fn (object $forecast): array => [
                ...$this->totalRow($forecast->game, $forecast->predicted_total, $forecast->market_total),
                'game_start_at' => $forecast->game_start_at->timestamp, 'snapshot_id' => null,
                'canonical_prediction_id' => $forecast->canonical_prediction_id, 'canonical_lineage' => $forecast->canonical_lineage,
            ])->values();
        $limit = (int) $this->option('limit');

        return $limit > 0 ? $rows->take($limit)->values() : $rows;
    }

    private function loadRows(): Collection
    {
        $this->excludedGames = 0;
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam', 'sportEvent'])
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->where('season_type', (int) $this->option('season-type'))
            ->when($this->option('season'), fn ($query) => $query->where('season', (int) $this->option('season')))
            ->get()->keyBy('id');
        $snapshots = PredictionFeatureSnapshot::query()
            ->where('sport', 'nba')->where('prediction_table', 'nba_predictions')
            ->whereIn('game_id', $games->modelKeys())
            ->where('pregame_safe', true)->whereNotNull('model_run_id')
            ->where('availability_status', 'observed_pregame')
            ->whereNotNull('generated_at')->whereNotNull('features_available_at')
            ->orderByDesc('generated_at')->orderByDesc('id')->get()->groupBy('game_id');
        $dates = app(SportsDateWindowService::class);
        $rows = $games->map(function (Game $game) use ($snapshots, $dates): ?array {
            $start = $game->sportEvent?->starts_at;
            if ($start === null && filled($game->game_time)) {
                $start = $dates->gameDateTimeUtc($game->getAttributes()['game_date'] ?? null, $game->game_time);
            }
            if ($start === null) {
                $this->excludedGames++;

                return null;
            }
            $start = CarbonImmutable::instance($start);
            $snapshot = ($snapshots->get($game->id) ?? collect())->first(function (PredictionFeatureSnapshot $snapshot) use ($start): bool {
                return $snapshot->generated_at->lt($start) && $snapshot->features_available_at->lte($snapshot->generated_at)
                    && is_numeric(data_get($snapshot->outputs, 'predicted_total'))
                    && is_numeric(data_get($snapshot->market_context, 'market_total') ?? data_get($snapshot->outputs, 'market_total'));
            });
            if ($snapshot === null) {
                $this->excludedGames++;

                return null;
            }
            $modelTotal = (float) $snapshot->outputs['predicted_total'];
            $marketTotal = (float) (data_get($snapshot->market_context, 'market_total') ?? data_get($snapshot->outputs, 'market_total'));

            return [...$this->totalRow($game, $modelTotal, $marketTotal), 'game_start_at' => $start->getTimestamp(), 'snapshot_id' => $snapshot->id];
        })->filter()->sortByDesc('game_start_at')->values();
        $limit = (int) $this->option('limit');

        return $limit > 0 ? $rows->take($limit)->values() : $rows;
    }

    private function totalRow(Game $game, float $modelTotal, float $marketTotal): array
    {
        $actualTotal = (float) $game->home_score + (float) $game->away_score;
        $pick = $modelTotal > $marketTotal ? 'over' : ($modelTotal < $marketTotal ? 'under' : 'pass');
        $result = $actualTotal > $marketTotal ? 'over' : ($actualTotal < $marketTotal ? 'under' : 'push');

        return [
            'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
            'model_total' => $modelTotal, 'market_total' => $marketTotal, 'actual_total' => $actualTotal,
            'pick' => $pick, 'result' => $result, 'won' => $pick === $result,
            'push' => $result === 'push', 'edge' => abs($modelTotal - $marketTotal),
            'model_error' => abs($actualTotal - $modelTotal),
        ];

    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows): array
    {
        $threshold = (float) config('nba.betting.edge_thresholds.total');
        $recommended = $rows->filter(fn (array $row) => $row['pick'] !== 'pass' && (float) $row['edge'] >= $threshold)->values();
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
