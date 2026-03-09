<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\MLB\TeamStatResource;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TeamStatController extends AbstractTeamStatController
{
    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_STAT_RESOURCE = TeamStatResource::class;

    public function seasonAverages(Team $team): JsonResponse
    {
        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'method' => __FUNCTION__,
            'team_id' => $team->id,
            'season' => (int) date('Y'),
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'team_stats_season_averages',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_stats_season_averages_seconds', 120),
            resolver: function () use ($team): array {
                $currentSeason = (int) date('Y');

                $seasonStats = DB::table('mlb_team_stats')
                    ->join('mlb_games', 'mlb_team_stats.game_id', '=', 'mlb_games.id')
                    ->where('mlb_team_stats.team_id', $team->id)
                    ->where('mlb_games.season', $currentSeason)
                    ->where('mlb_games.status', 'STATUS_FINAL')
                    ->selectRaw('
                        COUNT(*) as games_played,
                        AVG(runs) as runs_per_game,
                        AVG(hits) as hits_per_game,
                        AVG(home_runs) as home_runs_per_game,
                        AVG(rbis) as rbis_per_game,
                        AVG(walks) as walks_per_game,
                        AVG(strikeouts) as strikeouts_per_game,
                        AVG(stolen_bases) as stolen_bases_per_game,
                        AVG(batting_average) as batting_average,
                        AVG(doubles) as doubles_per_game,
                        AVG(triples) as triples_per_game,
                        AVG(errors) as errors_per_game,
                        AVG(earned_runs) as earned_runs_per_game,
                        AVG(era) as era
                    ')
                    ->first();

                return ['data' => $seasonStats];
            },
        );

        return response()->json($payload);
    }
}
