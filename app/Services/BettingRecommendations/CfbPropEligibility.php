<?php

namespace App\Services\BettingRecommendations;

use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CfbPropEligibility
{
    public const MARKETS = ['player_pass_yds', 'player_rush_yds', 'player_reception_yds'];

    public static function kickoff(Game $game): CarbonImmutable
    {
        return CarbonImmutable::parse($game->game_date->format('Y-m-d').' '.$game->game_time, 'UTC');
    }

    public static function onDate(Builder $query, string $date): Builder
    {
        $start = CarbonImmutable::parse($date, 'America/Chicago')->startOfDay()->utc();
        $end = $start->setTimezone('America/Chicago')->addDay()->utc();

        return $query->where(function ($q) use ($start) {
            $q->whereDate('game_date', '>', $start->toDateString())
                ->orWhere(fn ($q) => $q->whereDate('game_date', $start->toDateString())->where('game_time', '>=', $start->format('H:i:s')));
        })->where(function ($q) use ($end) {
            $q->whereDate('game_date', '<', $end->toDateString())
                ->orWhere(fn ($q) => $q->whereDate('game_date', $end->toDateString())->where('game_time', '<', $end->format('H:i:s')));
        });
    }

    public static function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

    public static function matchPlayer(Game $game, string $name): ?Player
    {
        $matches = Player::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->get()
            ->filter(fn ($player) => self::normalize((string) $player->full_name) === self::normalize($name));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public static function eligible(PlayerProp $prop): bool
    {
        $game = $prop->game;
        $player = $prop->player;
        if (! $game || ! $player || ! $prop->is_current || $prop->graded_at
            || ! in_array($prop->market, self::MARKETS, true)
            || ! in_array($player->team_id, [$game->home_team_id, $game->away_team_id], true)
            || $game->status !== 'STATUS_SCHEDULED' || self::kickoff($game)->isPast()
            || ! $prop->fetched_at || $prop->fetched_at->lt(now()->subMinutes(90))
            || ! $prop->over_price || ! $prop->under_price) {
            return false;
        }

        $sourceUpdated = data_get($prop->raw_data, 'last_update');
        if ($sourceUpdated) {
            try {
                if (CarbonImmutable::parse($sourceUpdated)->lt(now()->subMinutes(90))) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        // An unresolved availability report blocks a pick; absence of a report is not proof of health.
        return ! $player->activeInjuries()->whereRaw("LOWER(status) NOT IN ('active', 'available', 'probable')")->exists();
    }
}
