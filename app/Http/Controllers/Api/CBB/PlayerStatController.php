<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\CBB\PlayerStatResource;
use App\Models\CBB\Game;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
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
