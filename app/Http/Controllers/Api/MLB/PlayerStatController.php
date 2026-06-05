<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\MLB\PlayerStatResource;
use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Services\PlayerStats\MlbPlayerLeaderboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const MAX_PER_PAGE = 250;

    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;

    public function __construct(
        protected MlbPlayerLeaderboardService $leaderboardService,
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

    protected function applySeasonFiltersToStatsQuery($query, Request $request)
    {
        $query = parent::applySeasonFiltersToStatsQuery($query, $request);

        if ($request->filled('stat_type')) {
            $query->where('mlb_player_stats.stat_type', (string) $request->input('stat_type'));
        }

        return $query;
    }

    protected function applyByPlayerOrdering($query, Request $request)
    {
        if ($request->filled('season') || $request->filled('season_type')) {
            return $query
                ->orderByDesc('mlb_games.game_date')
                ->orderByDesc('mlb_games.game_time')
                ->orderByDesc('mlb_player_stats.id');
        }

        return parent::applyByPlayerOrdering($query, $request);
    }

    protected function getLeaderboardData(Request $request): Collection
    {
        $minGames = (int) ($request->integer('min_games') ?: 10);
        $season = $request->filled('season') ? (int) $request->integer('season') : null;
        $seasonTypeCandidates = $this->requestedSeasonTypeCandidates($request);
        $statType = $request->filled('stat_type') ? (string) $request->input('stat_type') : null;

        return $this->leaderboardService->execute($minGames, $season, $seasonTypeCandidates, $statType);
    }
}
