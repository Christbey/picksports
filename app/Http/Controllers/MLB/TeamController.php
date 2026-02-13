<?php

namespace App\Http\Controllers\MLB;

use App\Http\Controllers\Controller;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Team $team): Response
    {
        $currentSeason = (int) date('Y');

        // Get team metrics for current season
        $metrics = TeamMetric::query()
            ->where('team_id', $team->id)
            ->orderByDesc('season')
            ->first();

        // Get recent completed games (last 10)
        $recentGames = Game::query()
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->where('status', 'STATUS_FINAL')
            ->orderByDesc('game_date')
            ->with(['homeTeam', 'awayTeam'])
            ->limit(10)
            ->get();

        // Get upcoming games (next 5)
        $upcomingGames = Game::query()
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->where('status', 'STATUS_SCHEDULED')
            ->where('game_date', '>=', now()->toDateString())
            ->orderBy('game_date')
            ->with(['homeTeam', 'awayTeam'])
            ->limit(5)
            ->get();

        // Calculate season averages from team stats
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

        return Inertia::render('MLB/Team', [
            'team' => $team,
            'metrics' => $metrics,
            'recentGames' => $recentGames,
            'upcomingGames' => $upcomingGames,
            'seasonStats' => $seasonStats,
        ]);
    }
}
