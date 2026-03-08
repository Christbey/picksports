<?php

namespace App\Services;

use App\DataTransferObjects\ESPN\GameData;
use App\Events\GameFinalized;
use Illuminate\Database\Eloquent\Model;

class GameFinalizationDispatcher
{
    /**
     * @var array<class-string<Model>, string>
     */
    private const SPORT_BY_GAME_MODEL = [
        \App\Models\NBA\Game::class => 'nba',
        \App\Models\NFL\Game::class => 'nfl',
        \App\Models\MLB\Game::class => 'mlb',
        \App\Models\CBB\Game::class => 'cbb',
        \App\Models\CFB\Game::class => 'cfb',
        \App\Models\WNBA\Game::class => 'wnba',
        \App\Models\WCBB\Game::class => 'wcbb',
    ];

    public function dispatchIfFinalizedTransition(Model $game, ?string $previousStatus): void
    {
        $currentStatus = (string) ($game->status ?? '');

        $wasFinal = in_array((string) $previousStatus, GameData::finalStatuses(), true);
        $isFinal = in_array($currentStatus, GameData::finalStatuses(), true);

        if ($wasFinal || ! $isFinal) {
            return;
        }

        $sport = self::SPORT_BY_GAME_MODEL[$game::class] ?? null;
        if ($sport === null) {
            return;
        }

        event(new GameFinalized(
            sport: $sport,
            gameId: (int) $game->getKey(),
            season: isset($game->season) ? (int) $game->season : null,
            gameModelClass: $game::class,
        ));
    }
}
