<?php

namespace App\Console\Commands\Sports;

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\MarketQuote;
use App\Models\NBA\Game;
use App\Support\MLB\MlbLineScores;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class SettleBetDecisionsCommand extends Command
{
    protected $signature = 'sports:settle-bet-decisions
        {--sport= : Restrict to one sport}
        {--limit=0 : Optional decision limit}';

    protected $description = 'Settle immutable decisions and retain actual and counterfactual feedback';

    /**
     * @var array<string, class-string<Model>>
     */
    private array $gameModels = [
        'nba' => Game::class,
        'nfl' => \App\Models\NFL\Game::class,
        'mlb' => \App\Models\MLB\Game::class,
    ];

    public function handle(): int
    {
        $query = BetDecision::query()
            ->whereDoesntHave('settlement')
            ->when($this->option('sport'), fn ($builder) => $builder->where('sport', strtolower((string) $this->option('sport'))))
            ->orderBy('id');

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $settled = 0;
        foreach ($query->get() as $decision) {
            $marketType = $this->marketType($decision);
            $gameModel = $this->gameModels[$decision->sport] ?? null;
            $game = $gameModel === null ? null : $gameModel::query()->find($decision->game_id);
            $finalStatus = (string) config("{$decision->sport}.statuses.final", 'STATUS_FINAL');
            if ($marketType === null
                || ! $game
                || (string) $game->status !== $finalStatus
                || $game->home_score === null
                || $game->away_score === null) {
                continue;
            }

            $scores = $this->scoreContext($game, $marketType);
            if ($scores === null) {
                continue;
            }
            $homeScore = $scores['home'];
            $awayScore = $scores['away'];
            $homeMargin = $homeScore - $awayScore;
            $totalScore = $homeScore + $awayScore;
            $grade = $this->grade($decision, $marketType, $homeMargin, $totalScore);
            if ($grade === null) {
                continue;
            }

            $shadowProfit = $grade['push']
                ? 0.0
                : ($grade['won'] ? $this->winProfit($decision->price) : -1.0);
            $closingQuoteSelection = $this->closingQuote($decision, $marketType);
            $closingQuote = $closingQuoteSelection['quote'];
            $clv = $this->closingLineValue($decision, $closingQuote, $marketType);

            $settlement = BetSettlement::query()->firstOrCreate(
                ['bet_decision_id' => $decision->id],
                [
                    'result_status' => $grade['push'] ? 'push' : ($grade['won'] ? 'win' : 'loss'),
                    'result_value' => $grade['result_value'],
                    'profit_units' => $decision->is_bet ? $shadowProfit : 0.0,
                    'closing_price' => $closingQuote?->price,
                    'closing_line' => $closingQuote?->line,
                    'clv' => $clv['value'],
                    'graded_at' => now(),
                    'settled_at' => now(),
                    'metadata' => [
                        'market_type' => $marketType,
                        'side' => $decision->side,
                        'entry_line' => is_numeric($decision->line) ? (float) $decision->line : null,
                        'selected_result_value' => $grade['selected_result_value'],
                        'shadow_profit_units' => $shadowProfit,
                        'actual_bet_placed' => (bool) $decision->is_bet,
                        'home_score' => $homeScore,
                        'away_score' => $awayScore,
                        'home_margin' => $homeMargin,
                        'total_score' => $totalScore,
                        'period_innings' => $scores['innings'],
                        'closing_quote_id' => $closingQuote?->id,
                        'closing_quote_captured_at' => $closingQuote?->captured_at?->toIso8601String(),
                        'closing_quote_selection' => $closingQuoteSelection['selection'],
                        'entry_bookmaker' => $decision->bookmaker,
                        'closing_bookmaker' => $closingQuote?->bookmaker_key
                            ?? $closingQuote?->bookmaker_title,
                        'consensus_bookmaker_count' => $closingQuoteSelection['bookmaker_count'],
                        'clv_type' => $clv['type'],
                    ],
                ],
            );
            $settled += $settlement->wasRecentlyCreated ? 1 : 0;
        }

        $this->info("Settled {$settled} decision(s).");

        return self::SUCCESS;
    }

    private function marketType(BetDecision $decision): ?string
    {
        $marketType = strtolower(trim((string) $decision->market_type));
        $marketKey = strtolower(trim((string) $decision->market_key));

        return match (true) {
            $marketType === 'first_3_moneyline'
                || $marketKey === 'h2h_1st_3_innings' => 'first_3_moneyline',
            $marketType === 'first_5_moneyline'
                || $marketKey === 'h2h_1st_5_innings' => 'first_5_moneyline',
            in_array($marketType, ['moneyline', 'winner', 'win_probability'], true)
                || $marketKey === 'h2h' => 'moneyline',
            in_array($marketType, ['spread', 'run_line'], true)
                || in_array($marketKey, ['spread', 'spreads', 'run_line'], true) => 'spread',
            in_array($marketType, ['total', 'totals'], true)
                || in_array($marketKey, ['total', 'totals'], true) => 'total',
            default => null,
        };
    }

    /**
     * @return array{
     *     won: bool,
     *     push: bool,
     *     result_value: float,
     *     selected_result_value: float
     * }|null
     */
    private function grade(
        BetDecision $decision,
        string $marketType,
        int $homeMargin,
        int $totalScore,
    ): ?array {
        if (in_array($marketType, ['moneyline', 'first_3_moneyline', 'first_5_moneyline'], true)) {
            $selectedResult = match ($decision->side) {
                'home' => (float) $homeMargin,
                'away' => (float) -$homeMargin,
                default => null,
            };

            if ($selectedResult === null) {
                return null;
            }

            return [
                'won' => $selectedResult > 0,
                'push' => $selectedResult === 0.0,
                'result_value' => (float) $homeMargin,
                'selected_result_value' => $selectedResult,
            ];
        }

        if (! is_numeric($decision->line)) {
            return null;
        }

        $line = (float) $decision->line;
        if ($marketType === 'spread') {
            $selectedResult = match ($decision->side) {
                'home' => $homeMargin + $line,
                'away' => -$homeMargin + $line,
                default => null,
            };
            $resultValue = (float) $homeMargin;
        } else {
            $selectedResult = match ($decision->side) {
                'over' => $totalScore - $line,
                'under' => $line - $totalScore,
                default => null,
            };
            $resultValue = (float) $totalScore;
        }

        if ($selectedResult === null) {
            return null;
        }

        return [
            'won' => $selectedResult > 0.0,
            'push' => abs($selectedResult) < 0.000001,
            'result_value' => $resultValue,
            'selected_result_value' => $selectedResult,
        ];
    }

    /**
     * @return array{quote: ?MarketQuote, selection: ?string, bookmaker_count: int}
     */
    private function closingQuote(BetDecision $decision, string $marketType): array
    {
        $marketKey = match ($marketType) {
            'moneyline' => 'h2h',
            'first_3_moneyline', 'first_5_moneyline' => $decision->market_key,
            'spread' => 'spreads',
            'total' => 'totals',
        };

        $query = MarketQuote::query()
            ->where('sport', $decision->sport)
            ->where('game_id', $decision->game_id)
            ->where('market_key', $marketKey)
            ->where('side', $decision->side)
            ->where('is_pregame', true)
            ->when($decision->decided_at, fn ($builder) => $builder->where('captured_at', '>=', $decision->decided_at))
            ->when($decision->game_start_at, fn ($builder) => $builder->where('captured_at', '<=', $decision->game_start_at));

        $bookmaker = trim((string) $decision->bookmaker);
        if ($bookmaker !== '') {
            $exactQuote = (clone $query)
                ->where(function ($builder) use ($bookmaker): void {
                    $builder->where('bookmaker_key', $bookmaker)
                        ->orWhere('bookmaker_title', $bookmaker);
                })
                ->latest('captured_at')
                ->latest('id')
                ->first();

            if ($exactQuote !== null) {
                return [
                    'quote' => $exactQuote,
                    'selection' => 'exact_bookmaker',
                    'bookmaker_count' => 1,
                ];
            }
        }

        $latestByBookmaker = (clone $query)
            ->latest('captured_at')
            ->latest('id')
            ->get()
            ->unique(fn (MarketQuote $quote): string => (string) (
                $quote->bookmaker_key
                    ?? $quote->bookmaker_title
                    ?? "unknown:{$quote->id}"
            ))
            ->values();

        if ($latestByBookmaker->isEmpty()) {
            return [
                'quote' => null,
                'selection' => null,
                'bookmaker_count' => 0,
            ];
        }

        $metric = in_array(
            $marketType,
            ['moneyline', 'first_3_moneyline', 'first_5_moneyline'],
            true,
        ) ? 'no_vig_probability' : 'line';
        $consensusQuotes = $latestByBookmaker
            ->filter(fn (MarketQuote $quote): bool => is_numeric($quote->{$metric}))
            ->sortBy([
                [fn (MarketQuote $quote): float => (float) $quote->{$metric}, 'asc'],
                [fn (MarketQuote $quote): int => (int) $quote->id, 'asc'],
            ])
            ->values();

        if ($consensusQuotes->isEmpty()) {
            return [
                'quote' => null,
                'selection' => null,
                'bookmaker_count' => $latestByBookmaker->count(),
            ];
        }

        return [
            'quote' => $consensusQuotes->get(intdiv($consensusQuotes->count(), 2)),
            'selection' => 'consensus_fallback',
            'bookmaker_count' => $latestByBookmaker->count(),
        ];
    }

    /**
     * @return array{value: ?float, type: ?string}
     */
    private function closingLineValue(
        BetDecision $decision,
        ?MarketQuote $closingQuote,
        string $marketType,
    ): array {
        if ($closingQuote === null) {
            return ['value' => null, 'type' => null];
        }

        if (in_array($marketType, ['moneyline', 'first_3_moneyline', 'first_5_moneyline'], true)) {
            $entryProbability = is_numeric($decision->no_vig_probability)
                ? (float) $decision->no_vig_probability
                : null;
            $closingProbability = is_numeric($closingQuote->no_vig_probability)
                ? (float) $closingQuote->no_vig_probability
                : null;

            return [
                'value' => $entryProbability !== null && $closingProbability !== null
                    ? $closingProbability - $entryProbability
                    : null,
                'type' => 'probability',
            ];
        }

        $entryLine = is_numeric($decision->line) ? (float) $decision->line : null;
        $closingLine = is_numeric($closingQuote->line) ? (float) $closingQuote->line : null;
        if ($entryLine === null || $closingLine === null) {
            return ['value' => null, 'type' => 'line'];
        }

        $value = match ($marketType) {
            'spread' => $entryLine - $closingLine,
            'total' => $decision->side === 'over'
                ? $closingLine - $entryLine
                : $entryLine - $closingLine,
        };

        return ['value' => $value, 'type' => 'line'];
    }

    private function winProfit(?int $price): float
    {
        if ($price === null || $price === 0) {
            return 0.0;
        }

        return $price > 0 ? $price / 100 : 100 / abs($price);
    }

    /**
     * @return array{home: int, away: int, innings: ?int}|null
     */
    private function scoreContext(Model $game, string $marketType): ?array
    {
        $innings = match ($marketType) {
            'first_3_moneyline' => 3,
            'first_5_moneyline' => 5,
            default => null,
        };
        if ($innings === null) {
            return [
                'home' => (int) $game->home_score,
                'away' => (int) $game->away_score,
                'innings' => null,
            ];
        }

        $home = array_slice(MlbLineScores::normalize($game->home_linescores), 0, $innings);
        $away = array_slice(MlbLineScores::normalize($game->away_linescores), 0, $innings);
        if (count($home) < $innings
            || count($away) < $innings
            || collect([...$home, ...$away])->contains(
                fn (mixed $score): bool => ! is_numeric($score),
            )) {
            return null;
        }

        return [
            'home' => (int) array_sum(array_map('intval', $home)),
            'away' => (int) array_sum(array_map('intval', $away)),
            'innings' => $innings,
        ];
    }
}
