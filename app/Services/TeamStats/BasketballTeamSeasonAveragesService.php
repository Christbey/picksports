<?php

namespace App\Services\TeamStats;

use App\Support\StatsMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BasketballTeamSeasonAveragesService
{
    private const BASIC = ['points' => 'points_per_game', 'rebounds' => 'rebounds_per_game', 'assists' => 'assists_per_game',
        'steals' => 'steals_per_game', 'blocks' => 'blocks_per_game', 'turnovers' => 'turnovers_per_game'];

    private const EXTRA = ['offensive_rebounds' => 'offensive_rebounds_per_game', 'defensive_rebounds' => 'defensive_rebounds_per_game',
        'fast_break_points' => 'fast_break_points_per_game', 'points_in_paint' => 'points_in_paint_per_game',
        'second_chance_points' => 'second_chance_points_per_game', 'bench_points' => 'bench_points_per_game'];

    private const PAIRS = ['field_goal_percentage' => ['field_goals_made', 'field_goals_attempted'],
        'three_point_percentage' => ['three_point_made', 'three_point_attempted'], 'free_throw_percentage' => ['free_throws_made', 'free_throws_attempted']];

    /** @param class-string<Model> $teamStatModel */
    public function allTeams(string $teamStatModel, array $filters = []): Collection
    {
        return $this->aggregate($teamStatModel, $filters)['data'];
    }

    /** @param class-string<Model> $teamStatModel */
    public function forTeam(string $teamStatModel, int $teamId, bool $includeTeamId = false, bool $includeFouls = false, array $filters = []): ?array
    {
        $row = $this->aggregate($teamStatModel, $filters, $teamId, $includeFouls)['data']->first();
        if ($row !== null && ! $includeTeamId) {
            unset($row['team_id']);
        }

        return $row;
    }

    /** @param class-string<Model> $teamStatModel
     * @return array{data:Collection,scope:array<string,mixed>}
     */
    public function aggregate(string $teamStatModel, array $filters = [], ?int $teamId = null, bool $includeFouls = false): array
    {
        $scope = app(CompletedTeamStatScope::class)->resolve($teamStatModel, $filters, $teamId);
        $table = $scope['table'];
        $grammar = $scope['query']->getQuery()->getGrammar();
        $fields = self::BASIC + ($teamId !== null ? self::EXTRA : []);
        if ($includeFouls) {
            $fields['fouls'] = 'fouls_per_game';
        }
        $select = [$grammar->wrap("{$table}.team_id").' as team_id', 'COUNT(*) as games_played'];
        foreach ($fields as $column => $alias) {
            $select[] = 'AVG('.$grammar->wrap("{$table}.{$column}").") as {$alias}";
            $select[] = 'COUNT('.$grammar->wrap("{$table}.{$column}").") as samples_{$alias}";
        }
        foreach (self::PAIRS as $alias => [$made, $attempted]) {
            $madeColumn = $grammar->wrap("{$table}.{$made}");
            $attemptedColumn = $grammar->wrap("{$table}.{$attempted}");
            $known = "{$madeColumn} IS NOT NULL AND {$attemptedColumn} IS NOT NULL";
            $select[] = "SUM(CASE WHEN {$known} THEN {$madeColumn} END) as made_{$alias}";
            $select[] = "SUM(CASE WHEN {$known} THEN {$attemptedColumn} END) as attempted_{$alias}";
            $select[] = "COUNT(CASE WHEN {$known} THEN 1 END) as samples_{$alias}";
        }
        $rows = $scope['query']->selectRaw(implode(', ', $select))->groupBy("{$table}.team_id")->orderBy("{$table}.team_id")->get();
        $data = $rows->map(function (object $row) use ($scope, $fields): array {
            $result = ['team_id' => (int) $row->team_id, 'games_played' => (int) $row->games_played,
                'season' => $scope['metadata']['season'], 'season_type' => $scope['metadata']['season_type'], 'sample_sizes' => [], 'shooting_totals' => []];
            foreach ($fields as $alias) {
                $value = $this->number($row->{$alias});
                $result[$alias] = $value === null ? null : round($value, 1);
                $result['sample_sizes'][$alias] = (int) $row->{'samples_'.$alias};
            }
            foreach (self::PAIRS as $alias => $_) {
                $made = $this->number($row->{'made_'.$alias});
                $attempted = $this->number($row->{'attempted_'.$alias});
                $result['shooting_totals'][$alias] = ['made' => $made, 'attempted' => $attempted];
                $result[$alias] = $made === null || $attempted === null ? null : StatsMath::percentage($made, $attempted);
                $result['sample_sizes'][$alias] = (int) $row->{'samples_'.$alias};
            }

            return $result;
        })->values();

        return ['data' => $data, 'scope' => $scope['metadata']];
    }

    private function number(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
