<?php

namespace App\Support;

use App\Models\NFL\Game;
use Illuminate\Database\Eloquent\Model;

class NflGameStateGuard
{
    public static function preserve(Model $game, array $attributes): array
    {
        if (! $game instanceof Game) {
            return $attributes;
        }

        $resolver = app(EspnGameStatusResolver::class);
        $current = (string) $game->status;
        $incoming = (string) ($attributes['status'] ?? '');
        $regresses = $resolver->rank($incoming, 'nfl') < $resolver->rank($current, 'nfl');

        // A stale schedule must not erase the score while retaining a live/final status.
        foreach (['home_score', 'away_score', 'period', 'game_clock', 'home_linescores', 'away_linescores', 'completed_at'] as $field) {
            if ($regresses || (array_key_exists($field, $attributes) && $attributes[$field] === null && $game->{$field} !== null)) {
                unset($attributes[$field]);
            }
        }

        if ($regresses) {
            $attributes['status'] = $current;
        }

        return $attributes;
    }
}
