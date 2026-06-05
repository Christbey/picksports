<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\NFL\PlayerStatResource;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Services\PlayerStats\NflPlayerLeaderboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;

    public function __construct(
        protected NflPlayerLeaderboardService $leaderboardService
    ) {}

    protected function getByGameRelations(): array
    {
        return ['player', 'team'];
    }

    protected function getByPlayerRelations(): array
    {
        return ['game.homeTeam', 'game.awayTeam'];
    }

    protected function supportsLeaderboard(): bool
    {
        return true;
    }

    protected function getLeaderboardData(Request $request): Collection
    {
        $minGames = (int) ($request->integer('min_games') ?: 4);
        $season = $request->filled('season') ? (int) $request->integer('season') : null;
        $seasonTypeCandidates = $this->requestedSeasonTypeCandidates($request);

        return $this->leaderboardService->execute($minGames, $season, $seasonTypeCandidates);
    }
}
