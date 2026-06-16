<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReportProSignalsCommand extends Command
{
    protected $signature = 'nfl:report-pro-signals
        {--from-season= : First season to include}
        {--to-season= : Last season to include}
        {--min-sample=10 : Minimum sample size for reason-code rows}
        {--top=40 : Maximum reason-code rows to print}';

    protected $description = 'Report NFL pro signal layer ATS/OU, ROI proxy, CLV, and calibration by tier and reason code';

    public function handle(): int
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            $proLayerCount = $this->proLayerCount();
            $this->warn($proLayerCount > 0
                ? "Found {$proLayerCount} completed NFL prediction(s) with pro signal layers, but none had usable spread market data."
                : 'No completed NFL predictions with stored pro signal layers found for the selected seasons.'
            );

            return self::SUCCESS;
        }

        $this->info('NFL Pro Signal Layer Report');
        $this->line('Scope: '.$this->scopeLabel());
        $this->line('Rows: '.$rows->count());
        $this->newLine();

        $this->info('By Signal Tier');
        $this->table(
            ['Tier', 'Bets', 'Winner', 'Winner %', 'ATS', 'ATS Win %', 'OU', 'OU Win %', 'ROI Proxy', 'Avg CLV', 'Brier'],
            $this->summaryRows($rows->groupBy('tier'))
        );

        $this->newLine();
        $this->info('By Winner Tier');
        $this->table(
            ['Tier', 'Bets', 'Winner', 'Winner %', 'ATS', 'ATS Win %', 'OU', 'OU Win %', 'ROI Proxy', 'Avg CLV', 'Brier'],
            $this->summaryRows($rows->groupBy('winner_tier'))
        );

        $this->newLine();
        $this->info('By Spread Tier');
        $this->table(
            ['Tier', 'Bets', 'Winner', 'Winner %', 'ATS', 'ATS Win %', 'OU', 'OU Win %', 'ROI Proxy', 'Avg CLV', 'Brier'],
            $this->summaryRows($rows->groupBy('spread_tier'))
        );

        $totalRows = $rows->filter(fn (array $row): bool => $row['ou_result'] !== null);
        if ($totalRows->isNotEmpty()) {
            $this->newLine();
            $this->info('By Total Tier');
            $this->table(
                ['Tier', 'Bets', 'Winner', 'Winner %', 'ATS', 'ATS Win %', 'OU', 'OU Win %', 'ROI Proxy', 'Avg CLV', 'Brier'],
                $this->summaryRows($totalRows->groupBy('total_tier'))
            );
        }

        $reasonRows = $rows
            ->flatMap(function (array $row): array {
                return collect($row['reason_codes'])
                    ->map(fn (string $code): array => ['code' => $code, 'row' => $row])
                    ->all();
            })
            ->groupBy('code')
            ->filter(fn (Collection $items): bool => $items->count() >= (int) $this->option('min-sample'))
            ->map(fn (Collection $items, string $code): array => $this->reasonSummaryRow($code, $items->pluck('row')->values()))
            ->sortByDesc(fn (array $row): float => (float) $row[3])
            ->take((int) $this->option('top'))
            ->values()
            ->all();

        $this->newLine();
        $this->info('By Reason Code');
        $this->table(
            ['Reason Code', 'Bets', 'Winner', 'Winner %', 'ATS', 'ATS Win %', 'OU', 'OU Win %', 'ROI Proxy', 'Avg CLV', 'Brier'],
            $reasonRows
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function rows(): Collection
    {
        return Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query): void {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($this->option('from-season')) {
                    $query->where('season', '>=', (int) $this->option('from-season'));
                }

                if ($this->option('to-season')) {
                    $query->where('season', '<=', (int) $this->option('to-season'));
                }
            })
            ->get()
            ->map(fn (Prediction $prediction): ?array => $this->row($prediction))
            ->filter()
            ->values();
    }

    private function proLayerCount(): int
    {
        return Prediction::query()
            ->whereHas('game', function ($query): void {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');

                if ($this->option('from-season')) {
                    $query->where('season', '>=', (int) $this->option('from-season'));
                }

                if ($this->option('to-season')) {
                    $query->where('season', '<=', (int) $this->option('to-season'));
                }
            })
            ->get()
            ->filter(fn (Prediction $prediction): bool => is_array(data_get($prediction->model_metadata, 'analysis_layer.pro_signal_layer')))
            ->count();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(Prediction $prediction): ?array
    {
        $game = $prediction->game;
        $layer = data_get($prediction->model_metadata, 'analysis_layer.pro_signal_layer');
        $marketSpread = $this->number(data_get($layer, 'market_context.market_spread')) ?? ($game ? $this->entryMarketSpread($game) : null);
        $marketTotal = $this->number(data_get($layer, 'market_context.market_total')) ?? ($game ? $this->entryMarketTotal($game) : null);
        $totalEdge = $this->number(data_get($layer, 'market_context.total_edge'))
            ?? ($marketTotal !== null && $prediction->predicted_total !== null ? (float) $prediction->predicted_total - $marketTotal : null);
        $pick = data_get($layer, 'market_context.pick_side');
        if (! in_array($pick, ['home', 'away'], true) && $marketSpread !== null && $prediction->predicted_spread !== null) {
            $pick = (float) $prediction->predicted_spread >= $marketSpread ? 'home' : 'away';
        }

        if (! $game || ! is_array($layer) || ! in_array($pick, ['home', 'away'], true) || $marketSpread === null) {
            return null;
        }

        $actualMargin = (float) $game->home_score - (float) $game->away_score;
        $winnerCorrect = ($pick === 'home' && $actualMargin > 0)
            || ($pick === 'away' && $actualMargin < 0);
        $winnerPush = abs($actualMargin) < 0.0001;
        $coverMargin = $pick === 'home'
            ? $actualMargin - $marketSpread
            : (-$actualMargin) + $marketSpread;
        $result = abs($coverMargin) < 0.0001 ? 'push' : ($coverMargin > 0 ? 'win' : 'loss');
        $closingSpread = $this->closingMarketSpread($game);
        $clv = $closingSpread !== null ? $this->spreadClv($pick, $marketSpread, $closingSpread) : null;
        $totalResult = $this->totalResult($game, $marketTotal, $totalEdge);
        $score = $this->number($layer['score'] ?? null) ?? 0.0;
        $probability = max(0.01, min(0.99, $score / 100));
        $outcome = $result === 'win' ? 1.0 : 0.0;

        return [
            'tier' => (string) ($layer['tier'] ?? 'unknown'),
            'winner_tier' => (string) data_get($layer, 'market_scores.winner.tier', $layer['tier'] ?? 'unknown'),
            'spread_tier' => (string) data_get($layer, 'market_scores.spread.tier', $layer['tier'] ?? 'unknown'),
            'total_tier' => (string) data_get($layer, 'market_scores.total.tier', $layer['tier'] ?? 'unknown'),
            'reason_codes' => array_values(array_unique((array) ($layer['reason_codes'] ?? []))),
            'winner_correct' => $winnerCorrect,
            'winner_push' => $winnerPush,
            'result' => $result,
            'won' => $result === 'win',
            'push' => $result === 'push',
            'roi' => $result === 'push' ? 0.0 : ($result === 'win' ? 0.9091 : -1.0),
            'clv' => $clv,
            'brier' => ($probability - $outcome) ** 2,
            'ou_result' => $totalResult,
            'ou_won' => $totalResult === 'win',
            'ou_push' => $totalResult === 'push',
        ];
    }

    /**
     * @param  Collection<string,Collection<int,array<string,mixed>>>  $groups
     * @return array<int,array<int,string>>
     */
    private function summaryRows(Collection $groups): array
    {
        return $groups
            ->map(fn (Collection $items, string $tier): array => $this->summaryRow($tier, $items))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    private function reasonSummaryRow(string $code, Collection $items): array
    {
        return $this->summaryRow($code, $items);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    private function summaryRow(string $label, Collection $items): array
    {
        $winnerWins = $items->where('winner_correct', true)->count();
        $winnerPushes = $items->where('winner_push', true)->count();
        $winnerLosses = $items->count() - $winnerWins - $winnerPushes;
        $winnerGraded = $winnerWins + $winnerLosses;
        $wins = $items->where('won', true)->count();
        $pushes = $items->where('push', true)->count();
        $losses = $items->count() - $wins - $pushes;
        $graded = $wins + $losses;
        $ouItems = $items->filter(fn (array $row): bool => $row['ou_result'] !== null);
        $ouWins = $ouItems->where('ou_won', true)->count();
        $ouPushes = $ouItems->where('ou_push', true)->count();
        $ouLosses = $ouItems->count() - $ouWins - $ouPushes;
        $ouGraded = $ouWins + $ouLosses;
        $clvItems = $items->filter(fn (array $row): bool => $row['clv'] !== null);

        return [
            $label,
            (string) $items->count(),
            "{$winnerWins}-{$winnerLosses}-{$winnerPushes}",
            $winnerGraded > 0 ? number_format(($winnerWins / $winnerGraded) * 100, 1).'%' : 'n/a',
            "{$wins}-{$losses}-{$pushes}",
            $graded > 0 ? number_format(($wins / $graded) * 100, 1).'%' : 'n/a',
            $ouItems->isNotEmpty() ? "{$ouWins}-{$ouLosses}-{$ouPushes}" : 'n/a',
            $ouGraded > 0 ? number_format(($ouWins / $ouGraded) * 100, 1).'%' : 'n/a',
            number_format((float) $items->avg('roi'), 3),
            $clvItems->isNotEmpty() ? $this->signed((float) $clvItems->avg('clv'), 2) : 'n/a',
            number_format((float) $items->avg('brier'), 3),
        ];
    }

    private function totalResult(Game $game, ?float $marketTotal, ?float $totalEdge): ?string
    {
        if ($marketTotal === null || $totalEdge === null || abs($totalEdge) < (float) config('nfl.predictions.analysis_layer.min_total_edge', 3.0)) {
            return null;
        }

        $actualTotal = (float) $game->home_score + (float) $game->away_score;
        $margin = $totalEdge > 0 ? $actualTotal - $marketTotal : $marketTotal - $actualTotal;

        return abs($margin) < 0.0001 ? 'push' : ($margin > 0 ? 'win' : 'loss');
    }

    private function closingMarketSpread(Game $game): ?float
    {
        $snapshot = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->orderByDesc('captured_at')
            ->first();

        return $snapshot ? $this->homeMarginSpread((array) $snapshot->odds_data) : null;
    }

    private function entryMarketSpread(Game $game): ?float
    {
        return $this->homeMarginSpread($this->oddsData($game))
            ?? $this->snapshotMarketSpread($game, 'asc');
    }

    private function entryMarketTotal(Game $game): ?float
    {
        return $this->marketTotal($this->oddsData($game))
            ?? $this->snapshotMarketTotal($game, 'asc');
    }

    private function snapshotMarketSpread(Game $game, string $direction): ?float
    {
        $snapshot = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->orderBy('captured_at', $direction)
            ->first();

        return $snapshot ? $this->homeMarginSpread((array) $snapshot->odds_data) : null;
    }

    private function snapshotMarketTotal(Game $game, string $direction): ?float
    {
        $snapshot = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->orderBy('captured_at', $direction)
            ->first();

        return $snapshot ? $this->marketTotal((array) $snapshot->odds_data) : null;
    }

    private function spreadClv(string $pick, float $entrySpread, ?float $closingSpread): ?float
    {
        if ($closingSpread === null) {
            return null;
        }

        return $pick === 'home'
            ? $entrySpread - $closingSpread
            : $closingSpread - $entrySpread;
    }

    private function homeMarginSpread(array $oddsData): ?float
    {
        $homeTeamName = $oddsData['home_team'] ?? null;
        if (! is_string($homeTeamName) || $homeTeamName === '') {
            return null;
        }

        foreach ((array) ($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === $homeTeamName && is_numeric($outcome['point'] ?? null)) {
                        return -1 * (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    private function marketTotal(array $oddsData): ?float
    {
        foreach ((array) ($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'totals') {
                    continue;
                }

                foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
                    if (is_numeric($outcome['point'] ?? null)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function oddsData(Game $game): array
    {
        $oddsData = $game->odds_data;
        if (is_string($oddsData)) {
            $oddsData = json_decode($oddsData, true);
        }

        return is_array($oddsData) ? $oddsData : [];
    }

    private function scopeLabel(): string
    {
        return trim(($this->option('from-season') ?: 'first').' through '.($this->option('to-season') ?: 'latest'));
    }

    private function signed(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals);

        return $value > 0 ? '+'.$formatted : $formatted;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
