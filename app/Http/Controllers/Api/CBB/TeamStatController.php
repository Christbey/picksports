<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\CBB\TeamStatResource;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use App\Support\SportsViewCache;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;

class TeamStatController extends AbstractTeamStatController
{
    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_STAT_RESOURCE = TeamStatResource::class;

    public function __construct(
        protected BasketballTeamSeasonAveragesService $averagesService
    ) {}

    public function seasonAverages(Team $team)
    {
        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash(['controller' => static::class, 'method' => __FUNCTION__, 'team_id' => $team->id]);

        $payload = $sportsViewCache->remember(
            segment: 'team_stats_season_averages',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_stats_season_averages_seconds', 120),
            resolver: function () use ($team): array {
                $data = $this->averagesService->forTeam(TeamStat::class, $team->id);

                if (! $data) {
                    return ['data' => null, '__status' => 404];
                }

                return ['data' => $data];
            },
        );

        $status = (int) ($payload['__status'] ?? 200);
        unset($payload['__status']);

        return response()->json($payload, $status);
    }
}
