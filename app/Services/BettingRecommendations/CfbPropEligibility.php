<?php

namespace App\Services\BettingRecommendations;

use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use App\Services\CFB\CfbPlayerEvidenceService;
use App\Services\CFB\CfbPlayerMatcher;
use App\Support\CFB\CfbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CfbPropEligibility
{
    public const MARKETS = ['player_pass_yds', 'player_rush_yds', 'player_reception_yds'];

    public static function kickoff(Game $game): ?CarbonImmutable
    {
        return CfbGameStart::for($game);
    }

    public static function onDate(Builder $query, string $date): Builder
    {
        return app(CfbPropCalendarRead::class)->onDate($query, $date);
    }

    public static function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }

    public static function matchPlayer(Game $game, string $name): ?Player
    {
        return app(CfbPlayerMatcher::class)->match($game, $name);
    }

    public static function eligible(PlayerProp $prop): bool
    {
        if (! $prop->player_id) {
            return false;
        }
        $prop->loadMissing(['game', 'player.activeInjuries']);
        $game = $prop->game;
        $player = $prop->player;
        if (! $game || ! $player || ! $prop->is_current || $prop->graded_at
            || ! in_array($prop->market, self::MARKETS, true)
            || ! in_array($player->team_id, [$game->home_team_id, $game->away_team_id], true)
            || $game->status !== 'STATUS_SCHEDULED' || ! ($kickoff = self::kickoff($game)) || $kickoff->isPast()
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
        $unavailable = $player->relationLoaded('activeInjuries')
            ? $player->activeInjuries->contains(fn ($injury) => $injury->status !== null && ! in_array(strtolower($injury->status), ['active', 'available', 'probable'], true))
            : $player->activeInjuries()->whereRaw("LOWER(status) NOT IN ('active', 'available', 'probable')")->exists();

        return ! $unavailable
            && app(CfbPlayerEvidenceService::class)->forProp($prop)['hold_reasons'] === [];
    }
}
