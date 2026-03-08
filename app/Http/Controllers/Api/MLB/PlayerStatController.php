<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\MLB\PlayerStatResource;
use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;

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
        $season = $request->filled('season') ? (int) $request->integer('season') : null;
        $seasonTypeCandidates = $this->requestedSeasonTypeCandidates($request);

        $query = PlayerStat::query()
            ->join('mlb_players', 'mlb_players.id', '=', 'mlb_player_stats.player_id')
            ->join('mlb_games', 'mlb_games.id', '=', 'mlb_player_stats.game_id')
            ->leftJoin('mlb_teams', 'mlb_teams.id', '=', 'mlb_players.team_id')
            ->where('mlb_games.status', config('mlb.statuses.final'))
            ->selectRaw('
                mlb_player_stats.player_id,
                COUNT(DISTINCT mlb_player_stats.game_id) as games_played,
                SUM(COALESCE(mlb_player_stats.hits, 0)) as hits,
                SUM(COALESCE(mlb_player_stats.home_runs, 0)) as home_runs,
                SUM(COALESCE(mlb_player_stats.rbis, 0)) as rbis,
                SUM(COALESCE(mlb_player_stats.stolen_bases, 0)) as stolen_bases,
                SUM(COALESCE(mlb_player_stats.strikeouts, 0)) as strikeouts,
                SUM(COALESCE(mlb_player_stats.at_bats, 0)) as at_bats,
                SUM(COALESCE(mlb_player_stats.walks, 0)) as walks,
                SUM(COALESCE(mlb_player_stats.runs, 0)) as runs,
                SUM(COALESCE(mlb_player_stats.doubles, 0)) as doubles_total,
                SUM(COALESCE(mlb_player_stats.triples, 0)) as triples_total,
                SUM(COALESCE(mlb_player_stats.innings_pitched, 0)) as innings_pitched_total,
                SUM(COALESCE(mlb_player_stats.earned_runs, 0)) as earned_runs_total,
                SUM(COALESCE(mlb_player_stats.hits_allowed, 0)) as hits_allowed_total,
                SUM(COALESCE(mlb_player_stats.walks_allowed, 0)) as walks_allowed_total,
                SUM(COALESCE(mlb_player_stats.strikeouts_pitched, 0)) as strikeouts_pitched_total,
                SUM(COALESCE(mlb_player_stats.home_runs_allowed, 0)) as home_runs_allowed_total,
                AVG(COALESCE(mlb_player_stats.era, 0)) as era_fallback,
                AVG(COALESCE(mlb_player_stats.batting_average, 0)) as batting_average_fallback,
                AVG(COALESCE(mlb_player_stats.on_base_percentage, 0)) as on_base_percentage_fallback,
                AVG(COALESCE(mlb_player_stats.slugging_percentage, 0)) as slugging_percentage_fallback,
                mlb_players.id as player_ref_id,
                mlb_players.full_name,
                mlb_players.headshot_url,
                mlb_players.position,
                mlb_players.jersey_number,
                mlb_teams.id as team_id,
                mlb_teams.name as team_name,
                mlb_teams.location as team_city,
                mlb_teams.abbreviation as team_abbreviation
            ')
            ->groupBy([
                'mlb_player_stats.player_id',
                'mlb_players.id',
                'mlb_players.full_name',
                'mlb_players.headshot_url',
                'mlb_players.position',
                'mlb_players.jersey_number',
                'mlb_teams.id',
                'mlb_teams.name',
                'mlb_teams.location',
                'mlb_teams.abbreviation',
            ])
            ->havingRaw('COUNT(DISTINCT mlb_player_stats.game_id) >= ?', [$minGames]);

        if ($season !== null) {
            $query->where('mlb_games.season', $season);
        }

        if ($seasonTypeCandidates !== []) {
            $query->whereIn('mlb_games.season_type', $seasonTypeCandidates);
        }

        return $query
            ->orderByDesc(DB::raw('SUM(COALESCE(mlb_player_stats.hits, 0)) / NULLIF(COUNT(DISTINCT mlb_player_stats.game_id), 0)'))
            ->limit(1000)
            ->get()
            ->map(function ($row): array {
                $games = max(1, (int) $row->games_played);
                $hits = (float) $row->hits;
                $homeRuns = (float) $row->home_runs;
                $rbis = (float) $row->rbis;
                $stolenBases = (float) $row->stolen_bases;
                $strikeouts = (float) $row->strikeouts;
                $atBats = (float) $row->at_bats;
                $walks = (float) $row->walks;
                $runs = (float) $row->runs;
                $doubles = (float) $row->doubles_total;
                $triples = (float) $row->triples_total;
                $singles = max(0.0, $hits - $doubles - $triples - $homeRuns);
                $inningsPitched = (float) $row->innings_pitched_total;
                $earnedRuns = (float) $row->earned_runs_total;
                $hitsAllowed = (float) $row->hits_allowed_total;
                $walksAllowed = (float) $row->walks_allowed_total;
                $strikeoutsPitched = (float) $row->strikeouts_pitched_total;
                $homeRunsAllowed = (float) $row->home_runs_allowed_total;
                $eraFallback = (float) $row->era_fallback;
                $avgFallback = (float) $row->batting_average_fallback;
                $obpFallback = (float) $row->on_base_percentage_fallback;
                $slgFallback = (float) $row->slugging_percentage_fallback;

                if ($atBats <= 0 && $hits > 0 && $avgFallback > 0) {
                    $atBats = round($hits / $avgFallback, 1);
                }

                $avg = $atBats > 0 ? ($hits / $atBats) : max(0.0, $avgFallback);
                $obp = ($atBats + $walks) > 0
                    ? (($hits + $walks) / ($atBats + $walks))
                    : max(0.0, $obpFallback);
                $slug = $atBats > 0
                    ? (($singles + (2 * $doubles) + (3 * $triples) + (4 * $homeRuns)) / $atBats)
                    : max(0.0, $slgFallback);
                $avg = min(1.0, max(0.0, $avg));
                $obp = min(1.0, max(0.0, $obp));
                $slug = max(0.0, $slug);
                $era = $inningsPitched > 0
                    ? (($earnedRuns * 9) / $inningsPitched)
                    : max(0.0, $eraFallback);
                $whip = $inningsPitched > 0
                    ? (($walksAllowed + $hitsAllowed) / $inningsPitched)
                    : 0.0;

                return [
                    'player_id' => (int) $row->player_id,
                    'player' => [
                        'id' => (int) $row->player_ref_id,
                        'full_name' => $row->full_name,
                        'headshot_url' => $row->headshot_url,
                        'position' => $row->position,
                        'jersey_number' => $row->jersey_number,
                        'team' => $row->team_id ? [
                            'id' => (int) $row->team_id,
                            'name' => $row->team_name,
                            'display_name' => trim("{$row->team_city} {$row->team_name}"),
                            'abbreviation' => $row->team_abbreviation,
                        ] : null,
                    ],
                    'games_played' => (int) $row->games_played,
                    'points_per_game' => round($hits / $games, 2),
                    'rebounds_per_game' => round($homeRuns / $games, 2),
                    'assists_per_game' => round($rbis / $games, 2),
                    'steals_per_game' => round($stolenBases / $games, 2),
                    'blocks_per_game' => round($strikeouts / $games, 2),
                    'minutes_per_game' => round($atBats / $games, 1),
                    'field_goal_percentage' => round($avg, 3),
                    'three_point_percentage' => round($obp, 3),
                    'free_throw_percentage' => round($slug, 3),
                    'innings_pitched_per_game' => round($inningsPitched / $games, 2),
                    'era_per_game' => round($era, 2),
                    'whip_per_game' => round($whip, 3),
                    'strikeouts_pitched_per_game' => round($strikeoutsPitched / $games, 2),
                    'walks_allowed_per_game' => round($walksAllowed / $games, 2),
                    'hits_allowed_per_game' => round($hitsAllowed / $games, 2),
                    'home_runs_allowed_per_game' => round($homeRunsAllowed / $games, 2),
                ];
            })
            ->values();
    }
}
