<?php

namespace App\Console\Commands\Sports;

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\MarketQuote;
use App\Models\NBA\Game;
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
            ->where('market_type', 'moneyline')
            ->when($this->option('sport'), fn ($builder) => $builder->where('sport', strtolower((string) $this->option('sport'))))
            ->orderBy('id');

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $settled = 0;
        foreach ($query->get() as $decision) {
            $gameModel = $this->gameModels[$decision->sport] ?? null;
            $game = $gameModel === null ? null : $gameModel::query()->find($decision->game_id);
            $finalStatus = (string) config("{$decision->sport}.statuses.final", 'STATUS_FINAL');
            if (! $game
                || (string) $game->status !== $finalStatus
                || $game->home_score === null
                || $game->away_score === null) {
                continue;
            }

            $homeMargin = (int) $game->home_score - (int) $game->away_score;
            $won = match ($decision->side) {
                'home' => $homeMargin > 0,
                'away' => $homeMargin < 0,
                default => false,
            };
            $push = $homeMargin === 0;
            $shadowProfit = $push ? 0.0 : ($won ? $this->winProfit($decision->price) : -1.0);
            $closingQuote = MarketQuote::query()
                ->where('sport', $decision->sport)
                ->where('game_id', $decision->game_id)
                ->where('market_key', $decision->market_key)
                ->where('side', $decision->side)
                ->where('is_pregame', true)
                ->when($decision->game_start_at, fn ($builder) => $builder->where('captured_at', '<=', $decision->game_start_at))
                ->latest('captured_at')
                ->first();
            $entryProbability = is_numeric($decision->no_vig_probability) ? (float) $decision->no_vig_probability : null;
            $closingProbability = is_numeric($closingQuote?->no_vig_probability) ? (float) $closingQuote->no_vig_probability : null;
            $clv = $entryProbability !== null && $closingProbability !== null
                ? $closingProbability - $entryProbability
                : null;

            BetSettlement::query()->create([
                'bet_decision_id' => $decision->id,
                'result_status' => $push ? 'push' : ($won ? 'win' : 'loss'),
                'result_value' => $homeMargin,
                'profit_units' => $decision->is_bet ? $shadowProfit : 0.0,
                'closing_price' => $closingQuote?->price,
                'closing_line' => $closingQuote?->line,
                'clv' => $clv,
                'graded_at' => now(),
                'settled_at' => now(),
                'metadata' => [
                    'shadow_profit_units' => $shadowProfit,
                    'actual_bet_placed' => (bool) $decision->is_bet,
                    'home_score' => (int) $game->home_score,
                    'away_score' => (int) $game->away_score,
                    'closing_quote_id' => $closingQuote?->id,
                ],
            ]);
            $settled++;
        }

        $this->info("Settled {$settled} decision(s).");

        return self::SUCCESS;
    }

    private function winProfit(?int $price): float
    {
        if ($price === null || $price === 0) {
            return 0.0;
        }

        return $price > 0 ? $price / 100 : 100 / abs($price);
    }
}
