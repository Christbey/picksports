<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\MarketQuote;
use Illuminate\Database\Eloquent\Model;

/** Market references for model directions, independent of bet approval. */
class PredictionModelBetContext
{
    public function forPrediction(CanonicalPrediction $prediction, ?Model $game): array
    {
        $context = ['home_spread' => null, 'total' => null, 'captured_at' => null];
        $kickoff = $prediction->sportEvent?->starts_at?->copy()->setTimezone(config('app.timezone'));
        if (! $game || ! $kickoff) {
            return $context;
        }
        $quotes = MarketQuote::where('sport', $prediction->sport)->where('game_table', $game->getTable())
            ->where('game_id', $game->getKey())->where('is_pregame', true)
            ->whereIn('market_key', ['spreads', 'totals'])->where('captured_at', '<', $kickoff)
            ->where('captured_at', '<=', now())->orderByDesc('captured_at')->orderByDesc('id')->limit(200)->get();
        $names = fn ($team) => collect([$team?->display_name, $team?->name, $team?->school, $team?->short_display_name, trim(($team?->school ?? '').' '.($team?->mascot ?? ''))])
            ->filter()->map(fn ($name) => strtolower(trim($name)))->all();
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
