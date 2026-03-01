<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\CBB\PlayerStatResource;
use App\Models\CBB\Game;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use App\Services\PlayerStats\BasketballLeaderboardService;
use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;

    public function __construct(
        protected BasketballLeaderboardService $leaderboardService,
        protected NbaPlayerEpaCalculator $epaCalculator
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

        return $this->leaderboardService
            ->execute(PlayerStat::class, Player::class, $minGames)
            ->map(function (array $entry): array {
                $estimatedEpaPerGame = $this->epaCalculator->estimateFromBoxScore(
                    $entry['points_per_game'] ?? 0,
                    $entry['assists_per_game'] ?? 0,
                    $entry['rebounds_per_game'] ?? 0,
                    $entry['steals_per_game'] ?? 0,
                    $entry['blocks_per_game'] ?? 0,
                    $entry['turnovers_per_game'] ?? 0,
                    $entry['field_goals_made_per_game'] ?? 0,
                    $entry['field_goals_attempted_per_game'] ?? 0,
                    $entry['free_throws_made_per_game'] ?? 0,
                    $entry['free_throws_attempted_per_game'] ?? 0,
                    NbaPlayerEpaCalculator::PROFILE_CBB
                );

                $entry['estimated_epa_per_game'] = $estimatedEpaPerGame;
                $entry['estimated_epa_per_36'] = $this->epaCalculator->estimatePer36(
                    $estimatedEpaPerGame,
                    $entry['minutes_per_game'] ?? 0
                );

                return $entry;
            })
            ->values();
    }
}
