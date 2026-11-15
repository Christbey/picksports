<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\MarketQuote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Market references for model directions, independent of bet approval. */
class PredictionModelBetContext
{
    public const EMPTY_CONTEXT = ['home_spread' => null, 'total' => null, 'captured_at' => null];

    public function forPrediction(CanonicalPrediction $prediction, ?Model $game): array
    {
        $kickoff = $prediction->sportEvent?->starts_at?->copy()->setTimezone(config('app.timezone'));
        if (! $game || ! $kickoff) {
            return self::EMPTY_CONTEXT;
        }
        $quotes = $this->quoteQuery($prediction->sport, $game->getTable())
            ->where('game_id', $game->getKey())->where('captured_at', '<', $kickoff)->limit(200)->get();

        return $this->marketContext($game, $quotes);
    }

    /** @param Collection<int, CanonicalPrediction> $predictions */
    public function forDashboardBatch(string $sport, Collection $predictions): array
    {
        $games = [];
        $kickoffs = [];
        foreach ($predictions as $prediction) {
            $event = $prediction->sportEvent;
            $game = $event?->getRelation($sport.'Game');
            if ($game && $event->starts_at) {
                $games[$game->getKey()] = $game;
                $kickoffs[$game->getKey()] = $event->starts_at->setTimezone(config('app.timezone'));
            }
        }

        if ($games === []) {
            return [];
        }

        $quotes = $this->quoteQuery($sport, reset($games)->getTable())
            ->where(function ($query) use ($kickoffs): void {
                foreach ($kickoffs as $gameId => $kickoff) {
                    $query->orWhere(fn ($gameQuery) => $gameQuery->where('game_id', $gameId)->where('captured_at', '<', $kickoff));
                }
            })
            ->groupLimit(200, 'game_id')
            ->get(['id', 'game_id', 'game_odds_snapshot_id', 'market_key', 'side', 'line', 'participant', 'captured_at'])
            ->groupBy('game_id');

        $contexts = [];
        foreach ($quotes as $gameId => $gameQuotes) {
            $contexts[$gameId] = $this->marketContext($games[$gameId], $gameQuotes);
        }

        return $contexts;
    }

    private function quoteQuery(string $sport, string $table): Builder
    {
        return MarketQuote::where('sport', $sport)->where('game_table', $table)->where('is_pregame', true)
            ->whereIn('market_key', ['spreads', 'totals'])->where('captured_at', '<=', now())
            ->orderByDesc('captured_at')->orderByDesc('id');
    }

    private function marketContext(Model $game, Collection $quotes): array
    {
        $context = self::EMPTY_CONTEXT;
        $names = fn ($team) => collect([
            $team?->display_name,
            $team?->name,
            $team?->school,
            $team?->short_display_name,
            trim(($team?->school ?? '').' '.($team?->mascot ?? '')),
            $team?->location && $team?->name ? $team->location.' '.$team->name : null,
        ])->filter()->map(fn ($name) => strtolower(trim($name)))->all();
        $homeNames = $names($game->homeTeam);
        $awayNames = $names($game->awayTeam);
        foreach ($quotes->groupBy('game_odds_snapshot_id') as $snapshot) {
            $home = $snapshot->first(fn ($q) => $q->market_key === 'spreads' && $q->side === 'home');
            $away = $snapshot->first(fn ($q) => $q->market_key === 'spreads' && $q->side === 'away');
            if (! $home || ! $away || ! is_numeric($home->line) || ! is_numeric($away->line)
                || abs((float) $home->line + (float) $away->line) > 0.001
                || ! in_array(strtolower(trim((string) $home->participant)), $homeNames, true)
                || ! in_array(strtolower(trim((string) $away->participant)), $awayNames, true)) {
                continue;
            }
            $context['home_spread'] ??= (float) $home->line;
            $context['captured_at'] ??= $home->captured_at?->toIso8601String();
            $over = $snapshot->first(fn ($q) => $q->market_key === 'totals' && $q->side === 'over');
            $under = $snapshot->first(fn ($q) => $q->market_key === 'totals' && $q->side === 'under');
            if ($over && $under && is_numeric($over->line) && is_numeric($under->line)
                && (float) $over->line === (float) $under->line) {
                $context['total'] ??= (float) $over->line;
            }
            if ($context['total'] !== null) {
                break;
            }
        }

        return $context;
    }
}
