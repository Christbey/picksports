<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\WCBB\TeamStatResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamStat;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;
use App\Support\SportsViewCache;

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

    public function allSeasonAverages()
    {
        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash(['controller' => static::class, 'method' => __FUNCTION__]);

        $payload = $sportsViewCache->remember(
            segment: 'team_stats_all_season_averages',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_stats_all_season_averages_seconds', 120),
            resolver: fn (): array => [
                'data' => $this->averagesService->allTeams(TeamStat::class),
            ],
        );

        return response()->json($payload);
    }
}
