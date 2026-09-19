<?php

namespace App\Support;

use App\Models\NFL\Game;
use Illuminate\Database\Eloquent\Model;

class NflGameStateGuard
{
    public static function preserve(Model $game, array $attributes): array
    {
        if (! $game instanceof Game && ! $game instanceof \App\Models\CFB\Game) {
            return $attributes;
        }

        // ESPN's core schedule and summary endpoints omit metadata that is
        // present on the scoreboard. Absence is not a venue/identity change.
        foreach (['name', 'short_name', 'venue_name', 'venue_city', 'venue_state', 'stadium_id', 'roof', 'surface', 'broadcast_networks'] as $field) {
            if (array_key_exists($field, $attributes)
                && ($attributes[$field] === null || $attributes[$field] === '' || $attributes[$field] === [])
                && filled($game->{$field})) {
                unset($attributes[$field]);
            }
        }

        $resolver = app(EspnGameStatusResolver::class);
        $current = (string) $game->status;
        $incoming = (string) ($attributes['status'] ?? '');
        $sport = $game instanceof Game ? 'nfl' : 'cfb';
        $regresses = $resolver->rank($incoming, $sport) < $resolver->rank($current, $sport);

        // A stale schedule must not erase the score while retaining a live/final status.
        foreach (['home_score', 'away_score', 'period', 'game_clock', 'home_linescores', 'away_linescores', 'completed_at'] as $field) {
            if ($regresses || ($sport === 'nfl' && array_key_exists($field, $attributes) && $attributes[$field] === null && $game->{$field} !== null)) {
                unset($attributes[$field]);
            }
        }

        if ($regresses) {
            $attributes['status'] = $current;
        }

        return $attributes;
    }
}
