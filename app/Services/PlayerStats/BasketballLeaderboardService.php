<?php

namespace App\Services\PlayerStats;

use App\Support\StatsMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BasketballLeaderboardService
{
    public const OBSERVATION_POLICY = 'basketball_observed_fields_v1';

    public function __construct(private readonly NbaPlayerEpaCalculator $epa) {}

    /**
     * @param  class-string<Model>  $playerStatModel
     * @param  class-string<Model>  $playerModel
     */
    public function execute(
        string $playerStatModel,
        string $playerModel,
        int $minGames = 10,
        ?string $gameModel = null,
        ?int $season = null,
        ?array $seasonTypeCandidates = null
    ): Collection {
        $query = $playerStatModel::query()
            ->selectRaw('
                player_id,
                COUNT(*) as games_played,
                AVG(points) as points_per_game,
                AVG(rebounds_total) as rebounds_per_game,
                AVG(assists) as assists_per_game,
                AVG(turnovers) as turnovers_per_game,
                AVG(steals) as steals_per_game,
                AVG(blocks) as blocks_per_game,
                AVG(three_point_made) as three_pointers_made_per_game,
                AVG(field_goals_made) as field_goals_made_per_game,
                AVG(field_goals_attempted) as field_goals_attempted_per_game,
                AVG(free_throws_made) as free_throws_made_per_game,
                AVG(free_throws_attempted) as free_throws_attempted_per_game,
                SUM(CASE WHEN field_goals_made IS NOT NULL AND field_goals_attempted IS NOT NULL AND field_goals_made >= 0 AND field_goals_attempted >= 0 AND field_goals_made <= field_goals_attempted THEN field_goals_made END) as total_fg_made,
                SUM(CASE WHEN field_goals_made IS NOT NULL AND field_goals_attempted IS NOT NULL AND field_goals_made >= 0 AND field_goals_attempted >= 0 AND field_goals_made <= field_goals_attempted THEN field_goals_attempted END) as total_fg_attempted,
                SUM(CASE WHEN three_point_made IS NOT NULL AND three_point_attempted IS NOT NULL AND three_point_made >= 0 AND three_point_attempted >= 0 AND three_point_made <= three_point_attempted THEN three_point_made END) as total_3p_made,
                SUM(CASE WHEN three_point_made IS NOT NULL AND three_point_attempted IS NOT NULL AND three_point_made >= 0 AND three_point_attempted >= 0 AND three_point_made <= three_point_attempted THEN three_point_attempted END) as total_3p_attempted,
                SUM(CASE WHEN free_throws_made IS NOT NULL AND free_throws_attempted IS NOT NULL AND free_throws_made >= 0 AND free_throws_attempted >= 0 AND free_throws_made <= free_throws_attempted THEN free_throws_made END) as total_ft_made,
                SUM(CASE WHEN free_throws_made IS NOT NULL AND free_throws_attempted IS NOT NULL AND free_throws_made >= 0 AND free_throws_attempted >= 0 AND free_throws_made <= free_throws_attempted THEN free_throws_attempted END) as total_ft_attempted
            ');

        $sampleFields = array_values(array_unique([...NbaPlayerEpaCalculator::BOX_SCORE_FIELDS, 'three_point_made', 'three_point_attempted']));
        foreach ($sampleFields as $field) {
            $query->selectRaw("COUNT({$field}) as observed_{$field}");
        }
        $complete = implode(' AND ', array_map(static fn (string $field): string => "{$field} IS NOT NULL AND {$field} >= 0", NbaPlayerEpaCalculator::BOX_SCORE_FIELDS));
        $query->selectRaw("SUM(CASE WHEN {$complete} AND field_goals_made <= field_goals_attempted AND free_throws_made <= free_throws_attempted THEN 1 ELSE 0 END) as complete_box_score_games");
        foreach (['field_goals' => ['field_goals_made', 'field_goals_attempted'], 'three_point' => ['three_point_made', 'three_point_attempted'], 'free_throws' => ['free_throws_made', 'free_throws_attempted']] as $key => [$made, $attempt]) {
            $query->selectRaw("COUNT(CASE WHEN {$made} IS NOT NULL AND {$attempt} IS NOT NULL AND {$made} >= 0 AND {$attempt} >= 0 AND {$made} <= {$attempt} THEN 1 END) as observed_pair_{$key}");
        }

        if ($gameModel !== null) {
            $gameInstance = new $gameModel;
            $playerStatInstance = new $playerStatModel;
            $query->join(
                $gameInstance->getTable(),
                "{$gameInstance->getTable()}.id",
                '=',
                "{$playerStatInstance->getTable()}.game_id"
            );

            if ($season !== null) {
                $query->where("{$gameInstance->getTable()}.season", $season);
            }

            if (is_array($seasonTypeCandidates)) {
                $query->whereIn("{$gameInstance->getTable()}.season_type", $seasonTypeCandidates);
            }
        }

        $stats = $query
            ->groupBy('player_id')
            ->havingRaw('COUNT(*) >= ?', [$minGames])
            ->get();

        $playerIds = $stats->pluck('player_id');
        $minutesAverages = $this->averageMinutesByPlayer(
            $playerStatModel,
            $playerIds->all(),
            $gameModel,
            $season,
            $seasonTypeCandidates
        );
        $players = $playerModel::query()
            ->with('team')
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        return $stats->map(function ($row) use ($players, $minutesAverages, $sampleFields) {
            $player = $players->get($row->player_id);
            $minutes = $minutesAverages[(int) $row->player_id] ?? ['average' => null, 'samples' => 0];
            $minutesPerGame = $minutes['average'];

            return [
                'player_id' => $row->player_id,
                'player' => $player ? [
                    'id' => $player->id,
                    'full_name' => $player->full_name,
                    'headshot_url' => $player->headshot_url,
                    'position' => $player->position,
                    'jersey_number' => $player->jersey_number,
                    'team' => $player->team ? [
                        'id' => $player->team->id,
                        'name' => $player->team->name,
                        'display_name' => $player->team->display_name,
                        'abbreviation' => $player->team->abbreviation,
                    ] : null,
                ] : null,
                'games_played' => (int) $row->games_played,
                'points_per_game' => $row->points_per_game === null ? null : round((float) $row->points_per_game, 1),
                'rebounds_per_game' => $row->rebounds_per_game === null ? null : round((float) $row->rebounds_per_game, 1),
                'assists_per_game' => $row->assists_per_game === null ? null : round((float) $row->assists_per_game, 1),
                'turnovers_per_game' => $row->turnovers_per_game === null ? null : round((float) $row->turnovers_per_game, 1),
                'steals_per_game' => $row->steals_per_game === null ? null : round((float) $row->steals_per_game, 1),
                'blocks_per_game' => $row->blocks_per_game === null ? null : round((float) $row->blocks_per_game, 1),
                'three_pointers_made_per_game' => $row->three_pointers_made_per_game === null
                    ? null
                    : round((float) $row->three_pointers_made_per_game, 1),
                'minutes_per_game' => $minutesPerGame === null ? null : round($minutesPerGame, 1),
                'minutes_samples' => $minutes['samples'],
                'field_samples' => collect($sampleFields)->mapWithKeys(fn (string $field): array => [$field => (int) $row->{'observed_'.$field}])->all(),
                'complete_box_score_games' => (int) $row->complete_box_score_games,
                'shooting_samples' => ['field_goals' => (int) $row->observed_pair_field_goals, 'three_point' => (int) $row->observed_pair_three_point, 'free_throws' => (int) $row->observed_pair_free_throws],
                'field_goals_made_per_game' => $row->field_goals_made_per_game === null ? null : round((float) $row->field_goals_made_per_game, 1),
                'field_goals_attempted_per_game' => $row->field_goals_attempted_per_game === null ? null : round((float) $row->field_goals_attempted_per_game, 1),
                'free_throws_made_per_game' => $row->free_throws_made_per_game === null ? null : round((float) $row->free_throws_made_per_game, 1),
                'free_throws_attempted_per_game' => $row->free_throws_attempted_per_game === null ? null : round((float) $row->free_throws_attempted_per_game, 1),
                'field_goal_percentage' => ((int) $row->observed_pair_field_goals === 0 || (float) $row->total_fg_attempted <= 0) ? null : StatsMath::percentage($row->total_fg_made, $row->total_fg_attempted),
                'three_point_percentage' => ((int) $row->observed_pair_three_point === 0 || (float) $row->total_3p_attempted <= 0) ? null : StatsMath::percentage($row->total_3p_made, $row->total_3p_attempted),
                'free_throw_percentage' => ((int) $row->observed_pair_free_throws === 0 || (float) $row->total_ft_attempted <= 0) ? null : StatsMath::percentage($row->total_ft_made, $row->total_ft_attempted),
            ];
        })->values();
    }

    /**
     * @param  class-string<Model>  $playerStatModel
     * @param  array<int,int|string>  $playerIds
     * @return array<int,array{average:?float,samples:int}>
     */
    private function averageMinutesByPlayer(
        string $playerStatModel,
        array $playerIds,
        ?string $gameModel,
        ?int $season,
        ?array $seasonTypeCandidates = null
    ): array {
        if ($playerIds === []) {
            return [];
        }

        $query = $playerStatModel::query()
            ->select(['player_id', 'minutes_played'])
            ->whereIn('player_id', $playerIds);

        if ($gameModel !== null) {
            $gameInstance = new $gameModel;
            $playerStatInstance = new $playerStatModel;
            $query->join(
                $gameInstance->getTable(),
                "{$gameInstance->getTable()}.id",
                '=',
                "{$playerStatInstance->getTable()}.game_id"
            );

            if ($season !== null) {
                $query->where("{$gameInstance->getTable()}.season", $season);
            }

            if (is_array($seasonTypeCandidates)) {
                $query->whereIn("{$gameInstance->getTable()}.season_type", $seasonTypeCandidates);
            }
        }

        /** @var array<int,array{sum:float,count:int}> $buckets */
        $buckets = [];

        foreach ($query->get() as $row) {
            $playerId = (int) $row->player_id;
            $minutes = $this->epa->observedMinutes($row->minutes_played);
            if ($minutes === null) {
                continue;
            }

            if (! isset($buckets[$playerId])) {
                $buckets[$playerId] = ['sum' => 0.0, 'count' => 0];
            }

            $buckets[$playerId]['sum'] += $minutes;
            $buckets[$playerId]['count']++;
        }

        $averages = [];
        foreach ($buckets as $playerId => $bucket) {
            $averages[$playerId] = ['average' => $bucket['sum'] / $bucket['count'], 'samples' => $bucket['count']];
        }

        return $averages;
    }
}
