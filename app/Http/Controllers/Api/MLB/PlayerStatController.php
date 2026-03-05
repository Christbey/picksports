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

        return $query
            ->orderByDesc(DB::raw('SUM(COALESCE(mlb_player_stats.hits, 0)) / NULLIF(COUNT(DISTINCT mlb_player_stats.game_id), 0)'))
            ->limit(200)
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

                $avg = $atBats > 0 ? ($hits / $atBats) : 0.0;
                $obp = ($atBats + $walks) > 0 ? (($hits + $walks) / ($atBats + $walks)) : 0.0;
                $slug = $atBats > 0 ? (($singles + (2 * $doubles) + (3 * $triples) + (4 * $homeRuns)) / $atBats) : 0.0;

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
                ];
            })
            ->values();
    }
}
