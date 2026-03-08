<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractPlayerStatController;
use App\Http\Resources\NFL\PlayerStatResource;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Services\PlayerStats\NflPlayerEpaCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlayerStatController extends AbstractPlayerStatController
{
    protected const PLAYER_STAT_MODEL = PlayerStat::class;

    protected const PLAYER_MODEL = Player::class;

    protected const GAME_MODEL = Game::class;

    protected const PLAYER_STAT_RESOURCE = PlayerStatResource::class;

    public function __construct(
        protected NflPlayerEpaCalculator $epaCalculator
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

    protected function getLeaderboardData(Request $request): Collection
    {
        $minGames = (int) ($request->integer('min_games') ?: 4);
        $season = $request->filled('season') ? (int) $request->integer('season') : null;
        $seasonTypeCandidates = $this->requestedSeasonTypeCandidates($request);

        $query = PlayerStat::query()
            ->join('nfl_players', 'nfl_players.id', '=', 'nfl_player_stats.player_id')
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->leftJoin('nfl_teams', 'nfl_teams.id', '=', 'nfl_players.team_id')
            ->where('nfl_games.status', config('nfl.statuses.final'))
            ->selectRaw('
                nfl_player_stats.player_id,
                COUNT(DISTINCT nfl_player_stats.game_id) as games_played,
                SUM(COALESCE(nfl_player_stats.passing_yards, 0)) as passing_yards,
                SUM(COALESCE(nfl_player_stats.rushing_yards, 0)) as rushing_yards,
                SUM(COALESCE(nfl_player_stats.receiving_yards, 0)) as receiving_yards,
                SUM(COALESCE(nfl_player_stats.passing_touchdowns, 0)) as passing_touchdowns,
                SUM(COALESCE(nfl_player_stats.rushing_touchdowns, 0)) as rushing_touchdowns,
                SUM(COALESCE(nfl_player_stats.receiving_touchdowns, 0)) as receiving_touchdowns,
                SUM(COALESCE(nfl_player_stats.receptions, 0)) as receptions,
                SUM(COALESCE(nfl_player_stats.receiving_targets, 0)) as receiving_targets,
                SUM(COALESCE(nfl_player_stats.rushing_attempts, 0)) as rushing_attempts,
                SUM(COALESCE(nfl_player_stats.passing_completions, 0)) as passing_completions,
                SUM(COALESCE(nfl_player_stats.passing_attempts, 0)) as passing_attempts,
                SUM(COALESCE(nfl_player_stats.interceptions_thrown, 0)) as interceptions_thrown,
                SUM(COALESCE(nfl_player_stats.sacks_taken, 0)) as sacks_taken,
                SUM(COALESCE(nfl_player_stats.sack_yards_lost, 0)) as sack_yards_lost,
                SUM(COALESCE(nfl_player_stats.passing_two_point_conversions, 0)) as passing_two_point_conversions,
                SUM(COALESCE(nfl_player_stats.rushing_two_point_conversions, 0)) as rushing_two_point_conversions,
                SUM(COALESCE(nfl_player_stats.receiving_two_point_conversions, 0)) as receiving_two_point_conversions,
                SUM(CASE WHEN COALESCE(nfl_player_stats.interceptions_thrown, 0) > 0 THEN 1 ELSE 0 END) as games_with_interception,
                MAX(COALESCE(nfl_player_stats.passing_long, 0)) as passing_long,
                MAX(COALESCE(nfl_player_stats.rushing_long, 0)) as rushing_long,
                MAX(COALESCE(nfl_player_stats.receiving_long, 0)) as receiving_long,
                SUM(COALESCE(nfl_player_stats.kickoff_returns, 0)) as kickoff_returns,
                SUM(COALESCE(nfl_player_stats.kickoff_return_yards, 0)) as kickoff_return_yards,
                SUM(COALESCE(nfl_player_stats.kickoff_return_touchdowns, 0)) as kickoff_return_touchdowns,
                MAX(COALESCE(nfl_player_stats.kickoff_return_long, 0)) as kickoff_return_long,
                SUM(COALESCE(nfl_player_stats.kickoff_return_fair_catches, 0)) as kickoff_return_fair_catches,
                SUM(COALESCE(nfl_player_stats.punt_returns, 0)) as punt_returns,
                SUM(COALESCE(nfl_player_stats.punt_return_yards, 0)) as punt_return_yards,
                SUM(COALESCE(nfl_player_stats.punt_return_touchdowns, 0)) as punt_return_touchdowns,
                MAX(COALESCE(nfl_player_stats.punt_return_long, 0)) as punt_return_long,
                SUM(COALESCE(nfl_player_stats.punt_return_fair_catches, 0)) as punt_return_fair_catches,
                SUM(COALESCE(nfl_player_stats.fumbles_recovered, 0)) as fumbles_recovered,
                SUM(COALESCE(nfl_player_stats.tackles_total, 0)) as tackles_total,
                SUM(COALESCE(nfl_player_stats.sacks, 0)) as sacks,
                SUM(COALESCE(nfl_player_stats.interceptions, 0)) as defensive_interceptions,
                SUM(COALESCE(nfl_player_stats.passes_defended, 0)) as passes_defended,
                SUM(COALESCE(nfl_player_stats.field_goals_made, 0)) as field_goals_made,
                SUM(COALESCE(nfl_player_stats.field_goals_attempted, 0)) as field_goals_attempted,
                SUM(COALESCE(nfl_player_stats.extra_points_made, 0)) as extra_points_made,
                SUM(COALESCE(nfl_player_stats.extra_points_attempted, 0)) as extra_points_attempted,
                nfl_players.id as player_ref_id,
                nfl_players.full_name,
                nfl_players.headshot_url,
                nfl_players.position,
                nfl_players.jersey_number,
                nfl_teams.id as team_id,
                nfl_teams.name as team_name,
                nfl_teams.location as team_location,
                nfl_teams.abbreviation as team_abbreviation
            ')
            ->groupBy([
                'nfl_player_stats.player_id',
                'nfl_players.id',
                'nfl_players.full_name',
                'nfl_players.headshot_url',
                'nfl_players.position',
                'nfl_players.jersey_number',
                'nfl_teams.id',
                'nfl_teams.name',
                'nfl_teams.location',
                'nfl_teams.abbreviation',
            ])
            ->havingRaw('COUNT(DISTINCT nfl_player_stats.game_id) >= ?', [$minGames]);

        if ($season !== null) {
            $query->where('nfl_games.season', $season);
        }

        if ($seasonTypeCandidates !== []) {
            $query->whereIn('nfl_games.season_type', $seasonTypeCandidates);
        }

        return $query
            ->orderByDesc(DB::raw('(SUM(COALESCE(nfl_player_stats.passing_yards, 0)) + SUM(COALESCE(nfl_player_stats.rushing_yards, 0)) + SUM(COALESCE(nfl_player_stats.receiving_yards, 0))) / NULLIF(COUNT(DISTINCT nfl_player_stats.game_id), 0)'))
            ->limit(1000)
            ->get()
            ->map(function ($row): array {
                $games = max(1, (int) $row->games_played);
                $passingYards = (float) $row->passing_yards;
                $rushingYards = (float) $row->rushing_yards;
                $receivingYards = (float) $row->receiving_yards;
                $totalYards = $passingYards + $rushingYards + $receivingYards;
                $touchdowns = (float) $row->passing_touchdowns + (float) $row->rushing_touchdowns + (float) $row->receiving_touchdowns;
                $touches = (float) $row->rushing_attempts + (float) $row->receiving_targets;

                $position = strtoupper((string) ($row->position ?? ''));
                $attempts = (float) $row->passing_attempts;
                $completions = (float) $row->passing_completions;
                $interceptionsThrown = (float) $row->interceptions_thrown;
                $sacksTaken = (float) $row->sacks_taken;
                $sackYardsLost = (float) $row->sack_yards_lost;

                $compPct = $attempts > 0 ? (($completions / $attempts) * 100) : 0.0;
                $catchPct = ((float) $row->receiving_targets) > 0
                    ? (((float) $row->receptions / (float) $row->receiving_targets) * 100)
                    : 0.0;
                $yardsPerCarry = ((float) $row->rushing_attempts) > 0
                    ? ((float) $row->rushing_yards / (float) $row->rushing_attempts)
                    : 0.0;
                $fieldGoalPct = ((float) $row->field_goals_attempted) > 0
                    ? (((float) $row->field_goals_made / (float) $row->field_goals_attempted) * 100)
                    : 0.0;
                $extraPointPct = ((float) $row->extra_points_attempted) > 0
                    ? (((float) $row->extra_points_made / (float) $row->extra_points_attempted) * 100)
                    : 0.0;

                $passerRating = null;
                if ($attempts > 0 && $position === 'QB') {
                    $a = (($completions / $attempts) - 0.3) * 5;
                    $b = (($passingYards / $attempts) - 3) * 0.25;
                    $c = (((float) $row->passing_touchdowns / $attempts) * 20);
                    $d = 2.375 - (($interceptionsThrown / $attempts) * 25);
                    $a = max(0.0, min(2.375, $a));
                    $b = max(0.0, min(2.375, $b));
                    $c = max(0.0, min(2.375, $c));
                    $d = max(0.0, min(2.375, $d));
                    $passerRating = (($a + $b + $c + $d) / 6) * 100;
                }

                $passTdPct = $attempts > 0 ? (((float) $row->passing_touchdowns / $attempts) * 100) : 0.0;
                $passIntPct = $attempts > 0 ? (($interceptionsThrown / $attempts) * 100) : 0.0;
                $yardsPerPassThrown = $attempts > 0 ? ($passingYards / $attempts) : 0.0;
                $netPassingYards = $passingYards - $sackYardsLost;
                $netPassPlays = $attempts + $sacksTaken;
                $netYardsPerPassPlay = $netPassPlays > 0 ? ($netPassingYards / $netPassPlays) : 0.0;
                $gamesWithInterception = (int) round((float) $row->games_with_interception);
                $gamesWithInterceptionPct = $games > 0 ? (($gamesWithInterception / $games) * 100) : 0.0;
                $yardsPerKickoffReturn = ((float) $row->kickoff_returns) > 0
                    ? ((float) $row->kickoff_return_yards / (float) $row->kickoff_returns)
                    : 0.0;
                $yardsPerPuntReturn = ((float) $row->punt_returns) > 0
                    ? ((float) $row->punt_return_yards / (float) $row->punt_returns)
                    : 0.0;
                $passingRushingYards = $passingYards + $rushingYards;
                $rushingReceivingYards = $rushingYards + $receivingYards;
                $rushingReceivingTouchdowns = (float) $row->rushing_touchdowns + (float) $row->receiving_touchdowns;
                $estimatedEpaTotal = $this->epaCalculator->estimateFromBoxScore([
                    'passing_yards' => $passingYards,
                    'passing_touchdowns' => (float) $row->passing_touchdowns,
                    'interceptions_thrown' => $interceptionsThrown,
                    'sacks_taken' => $sacksTaken,
                    'sack_yards_lost' => $sackYardsLost,
                    'passing_two_point_conversions' => (float) $row->passing_two_point_conversions,
                    'rushing_yards' => $rushingYards,
                    'rushing_touchdowns' => (float) $row->rushing_touchdowns,
                    'rushing_attempts' => (float) $row->rushing_attempts,
                    'rushing_two_point_conversions' => (float) $row->rushing_two_point_conversions,
                    'receptions' => (float) $row->receptions,
                    'receiving_yards' => $receivingYards,
                    'receiving_touchdowns' => (float) $row->receiving_touchdowns,
                    'receiving_targets' => (float) $row->receiving_targets,
                    'receiving_two_point_conversions' => (float) $row->receiving_two_point_conversions,
                    'kickoff_return_yards' => (float) $row->kickoff_return_yards,
                    'kickoff_return_touchdowns' => (float) $row->kickoff_return_touchdowns,
                    'punt_return_yards' => (float) $row->punt_return_yards,
                    'punt_return_touchdowns' => (float) $row->punt_return_touchdowns,
                    'tackles_total' => (float) $row->tackles_total,
                    'sacks' => (float) $row->sacks,
                    'interceptions' => (float) $row->defensive_interceptions,
                    'passes_defended' => (float) $row->passes_defended,
                    'fumbles_recovered' => (float) $row->fumbles_recovered,
                    'field_goals_made' => (float) $row->field_goals_made,
                    'field_goals_attempted' => (float) $row->field_goals_attempted,
                    'extra_points_made' => (float) $row->extra_points_made,
                    'extra_points_attempted' => (float) $row->extra_points_attempted,
                ]);
                $opportunities = $attempts
                    + (float) $row->rushing_attempts
                    + (float) $row->receiving_targets
                    + (float) $row->kickoff_returns
                    + (float) $row->punt_returns
                    + (float) $row->field_goals_attempted
                    + (float) $row->extra_points_attempted
                    + (float) $row->tackles_total
                    + (float) $row->passes_defended
                    + (float) $row->defensive_interceptions
                    + (float) $row->fumbles_recovered;
                $estimatedEpaPerGame = $estimatedEpaTotal / $games;
                $estimatedEpaPerOpportunity = $this->epaCalculator->estimatePerOpportunity($estimatedEpaTotal, $opportunities);

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
                            'display_name' => trim("{$row->team_location} {$row->team_name}"),
                            'abbreviation' => $row->team_abbreviation,
                        ] : null,
                    ],
                    'games_played' => (int) $row->games_played,
                    // Reusing generic leaderboard fields for NFL
                    'points_per_game' => round($totalYards / $games, 1),
                    'rebounds_per_game' => round($touchdowns / $games, 2),
                    'assists_per_game' => round((float) $row->receptions / $games, 2),
                    'steals_per_game' => round($passingYards / $games, 1),
                    'blocks_per_game' => round($rushingYards / $games, 1),
                    'minutes_per_game' => round($touches / $games, 1),
                    'field_goal_percentage' => round($compPct, 1),
                    'three_point_percentage' => round($catchPct, 1),
                    'free_throw_percentage' => round($yardsPerCarry, 1),
                    'estimated_epa_total' => round($estimatedEpaTotal, 2),
                    'estimated_epa_per_game' => round($estimatedEpaPerGame, 2),
                    'estimated_epa_per_opportunity' => $estimatedEpaPerOpportunity,
                    'passing_yards_per_game' => round($passingYards / $games, 1),
                    'passing_touchdowns_per_game' => round((float) $row->passing_touchdowns / $games, 2),
                    'completion_percentage' => round($compPct, 1),
                    'interceptions_thrown_per_game' => round($interceptionsThrown / $games, 2),
                    'passing_yards_gross_total' => (int) round($passingYards),
                    'passing_yards_total' => (int) round($passingYards),
                    'passing_touchdowns_total' => (int) round((float) $row->passing_touchdowns),
                    'interceptions_thrown_total' => (int) round($interceptionsThrown),
                    'passing_attempts_total' => (int) round($attempts),
                    'passing_completions_total' => (int) round($completions),
                    'passing_touchdown_percentage' => round($passTdPct, 2),
                    'interception_percentage' => round($passIntPct, 2),
                    'games_with_interception' => $gamesWithInterception,
                    'games_with_interception_percentage' => round($gamesWithInterceptionPct, 1),
                    'yards_per_pass_thrown' => round($yardsPerPassThrown, 2),
                    'passing_long_total' => (int) round((float) $row->passing_long),
                    'sacks_taken_total' => (int) round($sacksTaken),
                    'sack_yards_lost_total' => (int) round($sackYardsLost),
                    'passing_yards_net_total' => (int) round($netPassingYards),
                    'net_yards_per_passing_play' => round($netYardsPerPassPlay, 2),
                    'qb_rating' => $passerRating !== null ? round($passerRating, 1) : null,
                    'qbr' => $passerRating !== null ? round($passerRating, 1) : null,
                    'passing_two_point_conversions_total' => (int) round((float) $row->passing_two_point_conversions),
                    'passing_rushing_yards_total' => (int) round($passingRushingYards),
                    'rushing_yards_per_game' => round($rushingYards / $games, 1),
                    'rushing_touchdowns_per_game' => round((float) $row->rushing_touchdowns / $games, 2),
                    'yards_per_carry' => round($yardsPerCarry, 1),
                    'receptions_per_game' => round((float) $row->receptions / $games, 2),
                    'receiving_yards_per_game' => round($receivingYards / $games, 1),
                    'rushing_yards_total' => (int) round($rushingYards),
                    'rushing_touchdowns_total' => (int) round((float) $row->rushing_touchdowns),
                    'rushing_attempts_total' => (int) round((float) $row->rushing_attempts),
                    'rushing_long_total' => (int) round((float) $row->rushing_long),
                    'rushing_two_point_conversions_total' => (int) round((float) $row->rushing_two_point_conversions),
                    'receptions_total' => (int) round((float) $row->receptions),
                    'receiving_yards_total' => (int) round($receivingYards),
                    'yards_per_reception' => round(((float) $row->receptions) > 0 ? ($receivingYards / (float) $row->receptions) : 0.0, 2),
                    'receiving_touchdowns_total' => (int) round((float) $row->receiving_touchdowns),
                    'receiving_long_total' => (int) round((float) $row->receiving_long),
                    'pass_targets_total' => (int) round((float) $row->receiving_targets),
                    'catch_rate' => round($catchPct, 1),
                    'receiving_two_point_conversions_total' => (int) round((float) $row->receiving_two_point_conversions),
                    'rushing_receiving_yards_total' => (int) round($rushingReceivingYards),
                    'rushing_receiving_touchdowns_total' => (int) round($rushingReceivingTouchdowns),
                    'kickoff_returns_total' => (int) round((float) $row->kickoff_returns),
                    'kickoff_return_yards_total' => (int) round((float) $row->kickoff_return_yards),
                    'yards_per_kickoff_return' => round($yardsPerKickoffReturn, 2),
                    'kickoff_return_touchdowns_total' => (int) round((float) $row->kickoff_return_touchdowns),
                    'kickoff_return_long_total' => (int) round((float) $row->kickoff_return_long),
                    'kickoff_return_fair_catches_total' => (int) round((float) $row->kickoff_return_fair_catches),
                    'punt_returns_total' => (int) round((float) $row->punt_returns),
                    'punt_return_yards_total' => (int) round((float) $row->punt_return_yards),
                    'yards_per_punt_return' => round($yardsPerPuntReturn, 2),
                    'punt_return_touchdowns_total' => (int) round((float) $row->punt_return_touchdowns),
                    'punt_return_long_total' => (int) round((float) $row->punt_return_long),
                    'punt_return_fair_catches_total' => (int) round((float) $row->punt_return_fair_catches),
                    'tackles_per_game' => round((float) $row->tackles_total / $games, 1),
                    'sacks_per_game' => round((float) $row->sacks / $games, 2),
                    'def_interceptions_per_game' => round((float) $row->defensive_interceptions / $games, 2),
                    'passes_defended_per_game' => round((float) $row->passes_defended / $games, 2),
                    'fumbles_recovered_per_game' => round((float) $row->fumbles_recovered / $games, 2),
                    'tackles_total' => (int) round((float) $row->tackles_total),
                    'sacks_total' => round((float) $row->sacks, 1),
                    'def_interceptions_total' => (int) round((float) $row->defensive_interceptions),
                    'passes_defended_total' => (int) round((float) $row->passes_defended),
                    'fumbles_recovered_total' => (int) round((float) $row->fumbles_recovered),
                    'field_goals_made_per_game' => round((float) $row->field_goals_made / $games, 2),
                    'extra_points_made_per_game' => round((float) $row->extra_points_made / $games, 2),
                    'field_goal_percentage_special' => round($fieldGoalPct, 1),
                    'extra_point_percentage' => round($extraPointPct, 1),
                    'field_goals_made_total' => (int) round((float) $row->field_goals_made),
                    'field_goals_attempted_total' => (int) round((float) $row->field_goals_attempted),
                    'extra_points_made_total' => (int) round((float) $row->extra_points_made),
                    'extra_points_attempted_total' => (int) round((float) $row->extra_points_attempted),
                ];
            })
            ->values();
    }
}
