<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Prediction;
use App\Services\NFL\NflTotalRuleSupport;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestTotalsCommand extends Command
{
    protected $signature = 'nfl:backtest-totals
        {--season= : Filter by season}
        {--from-season= : Analyze starting with this NFL season}
        {--to-season= : Analyze through this NFL season}
        {--limit=0 : Limit number of most recent final predictions to inspect}
        {--detailed : Show biggest misses}';

    protected $description = 'Backtest NFL over/under predictions against stored market totals and total-rule support';

    public function handle(): int
    {
        $rows = $this->loadRows();

        if ($rows->isEmpty()) {
            $this->warn('No final NFL predictions with totals market data found for the selected scope.');

            return self::SUCCESS;
        }

        $summary = $this->summarize($rows);

        $this->info('NFL Totals Backtest');
        $this->line('Scope: '.$this->scopeLabel());
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
                ['Raw O/U record', "{$summary['raw']['wins']}-{$summary['raw']['losses']}-{$summary['raw']['pushes']}"],
                ['Raw O/U win rate', number_format($summary['raw']['win_rate'], 1).'%'],
                ['Rule-gated O/U record', "{$summary['rule_gated']['wins']}-{$summary['rule_gated']['losses']}-{$summary['rule_gated']['pushes']}"],
                ['Rule-gated O/U win rate', number_format($summary['rule_gated']['win_rate'], 1).'%'],
                ['Rule-gated plays', (string) $summary['rule_gated']['count']],
                ['Playable O/U record', "{$summary['playable']['wins']}-{$summary['playable']['losses']}-{$summary['playable']['pushes']}"],
                ['Playable O/U win rate', number_format($summary['playable']['win_rate'], 1).'%'],
                ['Playable O/U bets', (string) $summary['playable']['count']],
                ['Rule-gated watchlist', (string) $summary['watchlist']['count']],
                ['CLV sample', (string) $summary['clv_sample']],
                ['Avg CLV', $summary['avg_clv'] !== null ? $this->signed($summary['avg_clv'], 2).' pts' : 'n/a'],
                ['Zero CLV rate', $summary['zero_clv_rate'] !== null ? number_format($summary['zero_clv_rate'], 1).'%' : 'n/a'],
            ]
        );

        foreach ($summary['market_quality_warnings'] as $warning) {
            $this->warn('Market data warning: '.$warning);
        }

        $this->newLine();
        $this->info('Rule-Gated By Edge Bucket');
        $this->table(['Bucket', 'Bets', 'Avg Edge', 'Win Rate'], $summary['edge_buckets']);

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Biggest Rule-Gated Misses');
            $this->table(
                ['Game', 'Model', 'Market', 'Actual', 'Pick', 'Signal', 'Result', 'Error', 'CLV'],
                $summary['biggest_misses']
            );
        }

        return self::SUCCESS;
    }

    private function loadRows(): Collection
    {
        $query = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereNotNull('predicted_total')
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

                $lineSet = $this->totalLineSet($game);
                if ($lineSet['entry_total'] === null) {
                    return null;
                }

                $actualTotal = (float) $game->home_score + (float) $game->away_score;
                $modelTotal = (float) $prediction->predicted_total;
                $marketTotal = (float) $lineSet['entry_total'];
                $pick = $modelTotal > $marketTotal ? 'over' : 'under';
                $result = $actualTotal > $marketTotal
                    ? 'over'
                    : ($actualTotal < $marketTotal ? 'under' : 'push');
                $support = app(NflTotalRuleSupport::class)->forPrediction($prediction, $pick);

                return [
                    'game' => $this->teamName($game->awayTeam).' @ '.$this->teamName($game->homeTeam),
                    'model_total' => $modelTotal,
                    'market_total' => $marketTotal,
                    'closing_total' => $lineSet['closing_total'],
                    'actual_total' => $actualTotal,
                    'pick' => $pick,
                    'result' => $result,
                    'won' => $pick === $result,
                    'push' => $result === 'push',
                    'edge' => abs($modelTotal - $marketTotal),
                    'model_error' => abs($actualTotal - $modelTotal),
                    'rule_support' => $support,
                    'rule_action' => $support['action'] ?? null,
                    'rule_label' => $support['label'] ?? null,
                    'playable' => $support !== null && $this->isPlayableTotal($support, abs($modelTotal - $marketTotal)),
                    'clv' => $this->totalClv($pick, $marketTotal, $lineSet['closing_total']),
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
        $threshold = (float) config('nfl.betting.edge_thresholds.total', 3.0);
        $raw = $rows->filter(fn (array $row): bool => (float) $row['edge'] >= $threshold)->values();
        $ruleGated = $raw->filter(fn (array $row): bool => $row['rule_support'] !== null)->values();
        $playable = $ruleGated->where('playable', true)->values();
        $watchlist = $ruleGated->where('playable', false)->values();
        $clvRows = $ruleGated->filter(fn (array $row): bool => $row['clv'] !== null)->values();
        $zeroClvRows = $clvRows->filter(fn (array $row): bool => abs((float) $row['clv']) < 0.0001)->count();
        $zeroClvRate = $clvRows->isNotEmpty() ? ($zeroClvRows / $clvRows->count()) * 100 : null;
        $warnings = [];

        if ($clvRows->count() >= 25 && $zeroClvRate !== null && $zeroClvRate >= 95.0) {
            $warnings[] = 'CLV is effectively flat, so totals closing-line timing/source is not validated';
        }

        return [
            'count' => $rows->count(),
            'avg_model_total' => (float) $rows->avg('model_total'),
            'avg_market_total' => (float) $rows->avg('market_total'),
            'avg_actual_total' => (float) $rows->avg('actual_total'),
            'model_mae' => (float) $rows->avg('model_error'),
            'market_mae' => (float) $rows->map(fn (array $row): float => abs((float) $row['actual_total'] - (float) $row['market_total']))->avg(),
            'raw' => $this->recordSummary($raw),
            'rule_gated' => $this->recordSummary($ruleGated),
            'playable' => $this->recordSummary($playable),
            'watchlist' => $this->recordSummary($watchlist),
            'clv_sample' => $clvRows->count(),
            'avg_clv' => $clvRows->isNotEmpty() ? round((float) $clvRows->avg('clv'), 3) : null,
            'zero_clv_rate' => $zeroClvRate,
            'market_quality_warnings' => $warnings,
            'edge_buckets' => $this->edgeBuckets($ruleGated),
            'biggest_misses' => $ruleGated
                ->sortByDesc('model_error')
                ->take(10)
                ->map(fn (array $row): array => [
                    $row['game'],
                    number_format((float) $row['model_total'], 1),
                    number_format((float) $row['market_total'], 1),
                    number_format((float) $row['actual_total'], 1),
                    strtoupper((string) $row['pick']),
                    strtoupper((string) ($row['rule_label'] ?? $row['rule_action'] ?? '')),
                    strtoupper((string) $row['result']),
                    number_format((float) $row['model_error'], 1),
                    $row['clv'] !== null ? $this->signed((float) $row['clv'], 2) : 'n/a',
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count:int,wins:int,losses:int,pushes:int,win_rate:float}
     */
    private function recordSummary(Collection $rows): array
    {
        $wins = $rows->where('won', true)->count();
        $pushes = $rows->where('push', true)->count();
        $losses = $rows->count() - $wins - $pushes;

        return [
            'count' => $rows->count(),
            'wins' => $wins,
            'losses' => $losses,
            'pushes' => $pushes,
            'win_rate' => ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) * 100 : 0.0,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function edgeBuckets(Collection $rows): array
    {
        $buckets = [
            '3.0-3.9' => fn (float $edge): bool => $edge >= 3.0 && $edge < 4.0,
            '4.0-5.9' => fn (float $edge): bool => $edge >= 4.0 && $edge < 6.0,
            '6.0+' => fn (float $edge): bool => $edge >= 6.0,
        ];

        return collect($buckets)
            ->map(function (callable $filter, string $label) use ($rows): ?array {
                $group = $rows->filter(fn (array $row): bool => $filter((float) $row['edge']))->values();
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
     * @return array{entry_total:?float,closing_total:?float}
     */
    private function totalLineSet(object $game): array
    {
        $entryTotal = $this->marketTotal(is_array($game->odds_data) ? $game->odds_data : null);
        $closingTotal = null;

        $closingSnapshot = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', (int) $game->getKey())
            ->orderByDesc('captured_at')
            ->first();

        if ($closingSnapshot !== null) {
            $closingTotal = $this->marketTotal(is_array($closingSnapshot->odds_data) ? $closingSnapshot->odds_data : null);
        }

        return [
            'entry_total' => $entryTotal,
            'closing_total' => $closingTotal,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oddsData
     */
    private function marketTotal(?array $oddsData): ?float
    {
        if ($oddsData === null) {
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

    private function totalClv(string $pick, float $entryTotal, ?float $closingTotal): ?float
    {
        if ($closingTotal === null) {
            return null;
        }

        return match ($pick) {
            'over' => round($closingTotal - $entryTotal, 3),
            'under' => round($entryTotal - $closingTotal, 3),
            default => null,
        };
    }

    /**
     * @param  array{action:string,rules:array<int, array<string, mixed>>,label:string}  $support
     */
    private function isPlayableTotal(array $support, float $edge): bool
    {
        $min = (float) config('nfl.betting.totals.play_min_edge', 4.0);
        $max = (float) config('nfl.betting.totals.play_max_edge', 6.0);
        $watchlistOnly = array_map('strval', (array) config('nfl.betting.totals.watchlist_only_rules', []));
        $rules = collect($support['rules'])
            ->map(fn (array $rule): string => (string) ($rule['name'] ?? ''))
            ->filter()
            ->values();

        if ($edge < $min || $edge >= $max) {
            return false;
        }

        return $rules->intersect($watchlistOnly)->isEmpty();
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
}
