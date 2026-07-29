<?php

namespace App\Console\Commands\NFL;

use App\Actions\Sports\Concerns\InteractsWithBettingMarkets;
use App\Models\GameOddsSnapshot;
use App\Models\NFL\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestMoneylinesCommand extends Command
{
    use InteractsWithBettingMarkets;

    protected $signature = 'nfl:backtest-moneylines
        {--season= : Filter by season}
        {--from-season= : Analyze starting with this NFL season}
        {--to-season= : Analyze through this NFL season}
        {--limit=0 : Limit number of most recent final predictions to inspect}
        {--detailed : Show biggest moneyline edges}';

    protected $description = 'Backtest NFL moneyline value using no-vig implied probability and stored h2h odds';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No final NFL predictions with h2h moneyline odds found for the selected scope.');
            $this->warn('Moneyline EV is ready for current odds, but historical ML validation requires imported h2h odds.');

            return self::SUCCESS;
        }

        $summary = $this->summarize($rows);

        $this->info('NFL Moneyline Backtest');
        $this->line('Scope: '.$this->scopeLabel());
        $this->line('Rows: '.$summary['count']);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Qualified ML bets', (string) $summary['qualified']['count']],
                ['ML record', "{$summary['qualified']['wins']}-{$summary['qualified']['losses']}"],
                ['ML win rate', number_format($summary['qualified']['win_rate'], 1).'%'],
                ['Flat-stake ROI', $this->signed($summary['qualified']['roi'], 1).'%'],
                ['Avg no-vig edge', $this->signed($summary['avg_edge'], 1).'%'],
                ['Avg model probability', number_format($summary['avg_model_probability'], 1).'%'],
                ['Avg no-vig implied', number_format($summary['avg_no_vig_implied'], 1).'%'],
                ['Avg market hold', number_format($summary['avg_market_hold'], 2).'%'],
                ['Home ML bets', "{$summary['home_bets']} (".number_format($summary['home_bet_rate'], 1).'%)'],
                ['Away ML bets', "{$summary['away_bets']} (".number_format($summary['away_bet_rate'], 1).'%)'],
            ]
        );

        $this->newLine();
        $this->info('By No-Vig Edge Bucket');
        $this->table(['Bucket', 'Bets', 'Avg Edge', 'Win Rate', 'ROI'], $summary['edge_buckets']);

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Biggest Moneyline Edges');
            $this->table(
                ['Game', 'Pick', 'Odds', 'Model', 'No-Vig', 'Edge', 'Result', 'Profit'],
                $summary['biggest_edges']
            );
        }

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('win_probability')
            ->whereHas('game', function ($query): void {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($this->option('season')) {
                    $query->where('season', (int) $this->option('season'));
                }

                if ($this->option('from-season')) {
                    $query->where('season', '>=', (int) $this->option('from-season'));
                }

                if ($this->option('to-season')) {
                    $query->where('season', '<=', (int) $this->option('to-season'));
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

                $prices = $this->moneylinePrices($game);
                if ($prices === null) {
                    return null;
                }

                $noVig = $this->noVigMoneylineProbabilities($prices['home'], $prices['away']);
                if ($noVig === null) {
                    return null;
                }

                $homeModelProb = max(0.0, min(1.0, (float) $prediction->win_probability));
                $awayModelProb = 1 - $homeModelProb;
                $homeEdge = $homeModelProb - $noVig['home'];
                $awayEdge = $awayModelProb - $noVig['away'];
                $betHome = $homeEdge >= $awayEdge;
                $edge = $betHome ? $homeEdge : $awayEdge;
                $trustScore = $this->analysisTrustScore($prediction);

                if ($edge < ((float) config('nfl.betting.moneyline.play_min_edge', 10.0) / 100)
                    || $trustScore < (float) config('nfl.betting.moneyline.play_min_trust', 85.0)) {
                    return null;
                }

                $won = $betHome
                    ? (float) $game->home_score > (float) $game->away_score
                    : (float) $game->away_score > (float) $game->home_score;
                $odds = $betHome ? $prices['home'] : $prices['away'];
                $profit = $won ? $this->americanPayoutPerUnit($odds) : -1.0;

                return [
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'pick' => $betHome ? 'home' : 'away',
                    'pick_team' => $betHome ? $this->teamName($game->homeTeam) : $this->teamName($game->awayTeam),
                    'odds' => $odds,
                    'model_probability' => ($betHome ? $homeModelProb : $awayModelProb) * 100,
                    'no_vig_implied' => ($betHome ? $noVig['home'] : $noVig['away']) * 100,
                    'edge' => $edge * 100,
                    'market_hold' => $noVig['hold'] * 100,
                    'won' => $won,
                    'profit' => $profit,
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
        $homeBets = $rows->where('pick', 'home')->count();
        $awayBets = $rows->where('pick', 'away')->count();

        return [
            'count' => $rows->count(),
            'qualified' => $this->recordSummary($rows),
            'avg_edge' => (float) $rows->avg('edge'),
            'avg_model_probability' => (float) $rows->avg('model_probability'),
            'avg_no_vig_implied' => (float) $rows->avg('no_vig_implied'),
            'avg_market_hold' => (float) $rows->avg('market_hold'),
            'home_bets' => $homeBets,
            'away_bets' => $awayBets,
            'home_bet_rate' => $rows->count() > 0 ? ($homeBets / $rows->count()) * 100 : 0.0,
            'away_bet_rate' => $rows->count() > 0 ? ($awayBets / $rows->count()) * 100 : 0.0,
            'edge_buckets' => $this->edgeBuckets($rows),
            'biggest_edges' => $rows
                ->sortByDesc('edge')
                ->take(10)
                ->map(fn (array $row): array => [
                    $row['game'],
                    $row['pick_team'].' ML',
                    $this->signedAmerican((float) $row['odds']),
                    number_format((float) $row['model_probability'], 1).'%',
                    number_format((float) $row['no_vig_implied'], 1).'%',
                    $this->signed((float) $row['edge'], 1).'%',
                    $row['won'] ? 'WIN' : 'LOSS',
                    $this->signed((float) $row['profit'], 2),
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count:int,wins:int,losses:int,win_rate:float,roi:float}
     */
    private function recordSummary(Collection $rows): array
    {
        $wins = $rows->where('won', true)->count();
        $losses = $rows->count() - $wins;
        $profit = (float) $rows->sum('profit');

        return [
            'count' => $rows->count(),
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0,
            'roi' => $rows->count() > 0 ? ($profit / $rows->count()) * 100 : 0.0,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function edgeBuckets(Collection $rows): array
    {
        $buckets = [
            '5.0-7.4%' => fn (float $edge): bool => $edge >= 5.0 && $edge < 7.5,
            '7.5-9.9%' => fn (float $edge): bool => $edge >= 7.5 && $edge < 10.0,
            '10.0%+' => fn (float $edge): bool => $edge >= 10.0,
        ];

        return collect($buckets)
            ->map(function (callable $filter, string $label) use ($rows): ?array {
                $group = $rows->filter(fn (array $row): bool => $filter((float) $row['edge']))->values();
                if ($group->isEmpty()) {
                    return null;
                }

                $record = $this->recordSummary($group);

                return [
                    $label,
                    (string) $group->count(),
                    number_format((float) $group->avg('edge'), 1).'%',
                    number_format($record['win_rate'], 1).'%',
                    $this->signed($record['roi'], 1).'%',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{home:float,away:float}|null
     */
    private function moneylinePrices(object $game): ?array
    {
        $oddsData = is_array($game->odds_data) ? $game->odds_data : null;
        $prices = $this->moneylinePricesFromOddsData($game, $oddsData);
        if ($prices !== null) {
            return $prices;
        }

        $snapshots = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', (int) $game->getKey())
            ->orderByDesc('captured_at')
            ->get();

        foreach ($snapshots as $snapshot) {
            $prices = $this->moneylinePricesFromOddsData(
                $game,
                is_array($snapshot->odds_data) ? $snapshot->odds_data : null
            );

            if ($prices !== null) {
                return $prices;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $oddsData
     * @return array{home:float,away:float}|null
     */
    private function moneylinePricesFromOddsData(object $game, ?array $oddsData): ?array
    {
        if ($oddsData === null) {
            return null;
        }

        $homePrice = null;
        $awayPrice = null;

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'h2h') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (! is_array($outcome) || ! is_numeric($outcome['price'] ?? null)) {
                        continue;
                    }

                    $name = (string) ($outcome['name'] ?? '');
                    if ($this->teamMatches($game->homeTeam, $name)) {
                        $homePrice = (float) $outcome['price'];
                    } elseif ($this->teamMatches($game->awayTeam, $name)) {
                        $awayPrice = (float) $outcome['price'];
                    }
                }
            }
        }

        return is_numeric($homePrice) && is_numeric($awayPrice)
            ? ['home' => (float) $homePrice, 'away' => (float) $awayPrice]
            : null;
    }

    private function teamMatches(mixed $team, string $outcomeName): bool
    {
        $outcome = strtolower(str_replace('los angeles', 'la', $outcomeName));

        foreach ([
            $team->location ?? null,
            $team->name ?? null,
            $team->abbreviation ?? null,
        ] as $token) {
            $token = strtolower(str_replace('los angeles', 'la', (string) $token));
            if ($token !== '' && str_contains($outcome, $token)) {
                return true;
            }
        }

        return false;
    }

    private function analysisTrustScore(Prediction $prediction): float
    {
        $metadata = is_array($prediction->model_metadata ?? null) ? $prediction->model_metadata : [];
        $trust = data_get($metadata, 'analysis_layer.trust_score');

        return is_numeric($trust) ? (float) $trust : (float) ($prediction->confidence_score ?? 0);
    }

    private function scopeLabel(): string
    {
        if ($this->option('season')) {
            return 'season '.$this->option('season');
        }

        if ($this->option('from-season') || $this->option('to-season')) {
            return 'seasons '.($this->option('from-season') ?: 'start').' to '.($this->option('to-season') ?: 'end');
        }

        return 'all seasons';
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

    private function signedAmerican(float $odds): string
    {
        return $odds > 0 ? '+'.number_format($odds, 0) : number_format($odds, 0);
    }
}
