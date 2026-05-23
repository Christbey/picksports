<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestSpreadsCommand extends Command
{
    protected $signature = 'nfl:backtest-spreads
        {--season= : Filter by season}
        {--limit=250 : Limit number of most recent final predictions to inspect}
        {--detailed : Show biggest model-market disagreements}';

    protected $description = 'Backtest NFL spread recommendations against stored market spreads and final margins';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No final NFL predictions with spread market data found for the selected scope.');

            return self::SUCCESS;
        }

        $summary = $this->summarize($rows);

        $this->info('NFL Spreads Backtest');
        $this->line('Scope: '.($this->option('season') ? 'season '.$this->option('season') : 'all seasons'));
        $this->line('Rows: '.$summary['count']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Avg model spread', number_format($summary['avg_model_spread'], 1)],
                ['Avg market spread', number_format($summary['avg_market_spread'], 1)],
                ['Avg actual margin', number_format($summary['avg_actual_margin'], 1)],
                ['Model bias vs market', $this->signed($summary['avg_model_spread'] - $summary['avg_market_spread'], 1)],
                ['Model bias vs actual', $this->signed($summary['avg_model_spread'] - $summary['avg_actual_margin'], 1)],
                ['Market MAE', number_format($summary['market_mae'], 2)],
                ['Model MAE', number_format($summary['model_mae'], 2)],
                ['CLV sample', (string) $summary['clv_sample']],
                ['Avg CLV', $summary['avg_clv'] !== null ? $this->signed($summary['avg_clv'], 2).' pts' : 'n/a'],
                ['Positive CLV rate', $summary['positive_clv_rate'] !== null ? number_format($summary['positive_clv_rate'], 1).'%' : 'n/a'],
                ['ATS record', "{$summary['wins']}-{$summary['losses']}-{$summary['pushes']}"],
                ['ATS win rate', number_format($summary['win_rate'], 1).'%'],
                ['Home-side bets', "{$summary['home_side_bets']} (".number_format($summary['home_side_rate'], 1).'%)'],
                ['Away-side bets', "{$summary['away_side_bets']} (".number_format($summary['away_side_rate'], 1).'%)'],
            ]
        );

        $this->newLine();
        $this->info('ATS By Edge Bucket');
        $this->table(['Bucket', 'Bets', 'Avg Edge', 'Win Rate'], $summary['edge_buckets']);

        $this->newLine();
        $this->info('Winner Accuracy By Confidence');
        $this->table(['Bucket', 'Games', 'Winner Accuracy', 'Avg Spread Error'], $summary['confidence_buckets']);

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Biggest Market Disagreements');
            $this->table(
                ['Game', 'Model', 'Market', 'Actual', 'Pick', 'Result', 'Edge', 'CLV'],
                $summary['biggest_disagreements']
            );
        }

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_spread')
            ->whereHas('game', function ($query): void {
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
            ->map(function (Prediction $prediction): ?array {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                $lineSet = $this->spreadLineSet($game);
                $homeMarketSpread = $lineSet['entry_home_spread'];
                if ($homeMarketSpread === null) {
                    return null;
                }

                $marketSpread = -$homeMarketSpread;
                $actualMargin = (float) $game->home_score - (float) $game->away_score;
                $modelSpread = (float) $prediction->predicted_spread;
                $edge = abs($modelSpread - $marketSpread);
                $pick = $modelSpread > $marketSpread ? 'home' : 'away';
                $coverMargin = $pick === 'home'
                    ? ($actualMargin - $marketSpread)
                    : ((-$actualMargin) + $marketSpread);
                $result = abs($coverMargin) < 0.0001
                    ? 'push'
                    : ($coverMargin > 0 ? 'win' : 'loss');
                $winnerCorrect = ($actualMargin > 0 && $modelSpread > 0)
                    || ($actualMargin < 0 && $modelSpread < 0);
                $closingMarketSpread = $lineSet['closing_home_spread'] !== null ? -$lineSet['closing_home_spread'] : null;
                $clv = $this->spreadClv($pick, $marketSpread, $closingMarketSpread);

                return [
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'home_team' => $this->teamName($game->homeTeam),
                    'away_team' => $this->teamName($game->awayTeam),
                    'model_spread' => $modelSpread,
                    'market_spread' => $marketSpread,
                    'market_home_line' => $homeMarketSpread,
                    'closing_spread' => $closingMarketSpread,
                    'closing_home_line' => $lineSet['closing_home_spread'],
                    'clv' => $clv,
                    'entry_source' => $lineSet['entry_source'],
                    'closing_source' => $lineSet['closing_source'],
                    'actual_margin' => $actualMargin,
                    'pick' => $pick,
                    'result' => $result,
                    'won' => $result === 'win',
                    'push' => $result === 'push',
                    'edge' => $edge,
                    'winner_correct' => $winnerCorrect,
                    'confidence_score' => (float) $prediction->confidence_score,
                    'model_error' => abs($actualMargin - $modelSpread),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows): array
    {
        $threshold = (float) config('nfl.betting.edge_thresholds.spread', 2.5);
        $recommended = $rows->filter(fn (array $row) => (float) $row['edge'] >= $threshold)->values();
        $wins = $recommended->where('won', true)->count();
        $pushes = $recommended->where('push', true)->count();
        $losses = $recommended->count() - $wins - $pushes;
        $homeSideBets = $recommended->where('pick', 'home')->count();
        $awaySideBets = $recommended->where('pick', 'away')->count();
        $clvRows = $recommended->filter(fn (array $row): bool => $row['clv'] !== null)->values();

        return [
            'count' => $rows->count(),
            'wins' => $wins,
            'losses' => $losses,
            'pushes' => $pushes,
            'win_rate' => ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0,
            'home_side_bets' => $homeSideBets,
            'away_side_bets' => $awaySideBets,
            'home_side_rate' => $recommended->count() > 0 ? ($homeSideBets / $recommended->count()) * 100 : 0.0,
            'away_side_rate' => $recommended->count() > 0 ? ($awaySideBets / $recommended->count()) * 100 : 0.0,
            'avg_model_spread' => (float) $rows->avg('model_spread'),
            'avg_market_spread' => (float) $rows->avg('market_spread'),
            'avg_actual_margin' => (float) $rows->avg('actual_margin'),
            'model_mae' => (float) $rows->avg('model_error'),
            'market_mae' => (float) $rows->map(fn (array $row) => abs($row['actual_margin'] - $row['market_spread']))->avg(),
            'clv_sample' => $clvRows->count(),
            'avg_clv' => $clvRows->isNotEmpty() ? round((float) $clvRows->avg('clv'), 3) : null,
            'positive_clv_rate' => $clvRows->isNotEmpty()
                ? ($clvRows->filter(fn (array $row): bool => (float) $row['clv'] > 0)->count() / $clvRows->count()) * 100
                : null,
            'edge_buckets' => $this->edgeBuckets($recommended),
            'confidence_buckets' => $this->confidenceBuckets($rows),
            'biggest_disagreements' => $recommended
                ->sortByDesc('edge')
                ->take(10)
                ->map(fn (array $row) => [
                    $row['game'],
                    $this->formatModelSpread((float) $row['model_spread'], (string) $row['home_team'], (string) $row['away_team']),
                    $this->formatSportsbookSpread((float) $row['market_home_line'], (string) $row['home_team'], (string) $row['away_team']),
                    number_format((float) $row['actual_margin'], 1),
                    $this->formatBetPick((string) $row['pick'], (float) $row['market_home_line'], (string) $row['home_team'], (string) $row['away_team']),
                    strtoupper((string) $row['result']),
                    number_format((float) $row['edge'], 1),
                    $row['clv'] !== null ? $this->signed((float) $row['clv'], 2) : 'n/a',
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function edgeBuckets(Collection $rows): array
    {
        $buckets = [
            '2.5-3.9' => fn (float $edge) => $edge >= 2.5 && $edge < 4.0,
            '4.0-5.9' => fn (float $edge) => $edge >= 4.0 && $edge < 6.0,
            '6.0+' => fn (float $edge) => $edge >= 6.0,
        ];

        return collect($buckets)
            ->map(function (callable $filter, string $label) use ($rows): ?array {
                $group = $rows->filter(fn (array $row) => $filter((float) $row['edge']))->values();
                if ($group->isEmpty()) {
                    return null;
                }

                $wins = $group->where('won', true)->count();
                $losses = $group->where('push', false)->count() - $wins;
                $winRate = ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0;

                return [$label, (string) $group->count(), number_format((float) $group->avg('edge'), 1), number_format($winRate, 1).'%'];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function confidenceBuckets(Collection $rows): array
    {
        $buckets = [
            '50-59' => fn (float $confidence) => $confidence < 60.0,
            '60-69' => fn (float $confidence) => $confidence >= 60.0 && $confidence < 70.0,
            '70-79' => fn (float $confidence) => $confidence >= 70.0 && $confidence < 80.0,
            '80+' => fn (float $confidence) => $confidence >= 80.0,
        ];

        return collect($buckets)
            ->map(function (callable $filter, string $label) use ($rows): ?array {
                $group = $rows->filter(fn (array $row) => $filter((float) $row['confidence_score']))->values();
                if ($group->isEmpty()) {
                    return null;
                }

                return [
                    $label,
                    (string) $group->count(),
                    number_format(((float) $group->where('winner_correct', true)->count() / max(1, $group->count())) * 100, 1).'%',
                    number_format((float) $group->avg('model_error'), 2),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $oddsData
     */
    private function homeMarketSpread(?array $oddsData, string $homeTeamName): ?float
    {
        if (! $oddsData || $homeTeamName === '') {
            return null;
        }

        foreach ($oddsData['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach ($market['outcomes'] ?? [] as $outcome) {
                    if (($outcome['name'] ?? null) === $homeTeamName && isset($outcome['point'])) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{entry_home_spread:?float,closing_home_spread:?float,entry_source:string,closing_source:?string}
     */
    private function spreadLineSet(Game $game): array
    {
        $snapshots = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', (int) $game->getKey())
            ->orderBy('captured_at')
            ->get();

        $entryOddsData = is_array($game->odds_data) ? $game->odds_data : null;
        $entryHomeSpread = $this->homeMarketSpread($entryOddsData, (string) ($entryOddsData['home_team'] ?? ''));
        $entrySource = 'game.odds_data';

        if ($entryHomeSpread === null && $snapshots->isNotEmpty()) {
            $entrySnapshot = $snapshots->first();
            $entryOddsData = is_array($entrySnapshot?->odds_data) ? $entrySnapshot->odds_data : null;
            $entryHomeSpread = $this->homeMarketSpread($entryOddsData, (string) ($entryOddsData['home_team'] ?? ''));
            $entrySource = 'snapshot:'.$entrySnapshot?->captured_at?->toDateTimeString();
        }

        $closingHomeSpread = null;
        $closingSource = null;
        $closingSnapshot = $snapshots->last();
        if ($closingSnapshot !== null) {
            $closingOddsData = is_array($closingSnapshot->odds_data) ? $closingSnapshot->odds_data : null;
            $closingHomeSpread = $this->homeMarketSpread($closingOddsData, (string) ($closingOddsData['home_team'] ?? ''));
            $closingSource = 'snapshot:'.$closingSnapshot->captured_at?->toDateTimeString();
        }

        return [
            'entry_home_spread' => $entryHomeSpread,
            'closing_home_spread' => $closingHomeSpread,
            'entry_source' => $entrySource,
            'closing_source' => $closingSource,
        ];
    }

    private function spreadClv(string $pick, float $entryMarketSpread, ?float $closingMarketSpread): ?float
    {
        if ($closingMarketSpread === null) {
            return null;
        }

        return match ($pick) {
            'home' => round($closingMarketSpread - $entryMarketSpread, 3),
            'away' => round($entryMarketSpread - $closingMarketSpread, 3),
            default => null,
        };
    }

    private function teamName(mixed $team): string
    {
        return (string) ($team->abbreviation ?? 'UNK');
    }

    private function signed(float $value, int $precision = 1): string
    {
        $formatted = number_format($value, $precision);

        return $value > 0 ? "+{$formatted}" : $formatted;
    }

    private function formatModelSpread(float $spread, string $homeTeam, string $awayTeam): string
    {
        if ($spread > 0) {
            return "{$homeTeam} -".number_format($spread, 1);
        }

        if ($spread < 0) {
            return "{$awayTeam} -".number_format(abs($spread), 1);
        }

        return 'PK';
    }

    private function formatSportsbookSpread(float $homeSpread, string $homeTeam, string $awayTeam): string
    {
        if ($homeSpread < 0) {
            return "{$homeTeam} ".number_format($homeSpread, 1);
        }

        if ($homeSpread > 0) {
            return "{$awayTeam} -".number_format($homeSpread, 1);
        }

        return 'PK';
    }

    private function formatBetPick(string $pick, float $homeSpread, string $homeTeam, string $awayTeam): string
    {
        if ($pick === 'home') {
            return 'Bet '.$homeTeam.' '.$this->signed($homeSpread, 1);
        }

        return 'Bet '.$awayTeam.' '.$this->signed(-$homeSpread, 1);
    }
}
