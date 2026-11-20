<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Services\BettingRecommendations\CfbPropEligibility;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use WeakMap;

#[Scoped]
final class CfbPlayerMatcher
{
    /** @var WeakMap<Game, Collection> */
    private WeakMap $rosters;

    public function __construct()
    {
        $this->rosters = new WeakMap;
    }

    public function match(Game $game, string $name): ?Player
    {
        $roster = $this->rosters[$game] ??= Player::query()->whereIn('team_id', [$game->home_team_id, $game->away_team_id])->get();
        $normalized = CfbPropEligibility::normalize($name);
        $matches = $roster->filter(fn (Player $player): bool => CfbPropEligibility::normalize((string) $player->full_name) === $normalized);

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
