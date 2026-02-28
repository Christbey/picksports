<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\NBA\PlayerStatResource;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Services\PlayerStats\BasketballLeaderboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;

    public function __construct(
        protected BasketballLeaderboardService $leaderboardService
    ) {}

    protected function getByGameRelations(): array
    {
        return ['player', 'team'];
    }

    protected function getByPlayerRelations(): array
    {
        return ['game.homeTeam', 'game.awayTeam'];
    }

    protected function getByPlayerPerPage(Request $request): int
    {
        return (int) ($request->integer('per_page') ?: 100);
    }

    protected function supportsLeaderboard(): bool
    {
        return true;
    }

    protected function getLeaderboardData(Request $request): Collection
    {
        $minGames = (int) ($request->integer('min_games') ?: 10);

        return $this->leaderboardService->execute(PlayerStat::class, Player::class, $minGames);
    }
}
