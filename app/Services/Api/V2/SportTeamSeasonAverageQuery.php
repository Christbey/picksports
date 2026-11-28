<?php

namespace App\Services\Api\V2;

use App\Services\TeamStats\BasketballTeamSeasonAveragesService;
use App\Services\TeamStats\CompletedTeamStatScope;
use Illuminate\Database\Eloquent\Model;

class SportTeamSeasonAverageQuery
{
    private const MLB_FIELDS = ['runs' => 'runs_per_game', 'hits' => 'hits_per_game', 'home_runs' => 'home_runs_per_game',
        'rbis' => 'rbis_per_game', 'walks' => 'walks_per_game', 'strikeouts' => 'strikeouts_per_game',
        'stolen_bases' => 'stolen_bases_per_game', 'batting_average' => 'batting_average', 'doubles' => 'doubles_per_game',
        'triples' => 'triples_per_game', 'errors' => 'errors_per_game', 'earned_runs' => 'earned_runs_per_game', 'era' => 'era'];

    public function allAverages(SportContext $context, BasketballTeamSeasonAveragesService $averages, array $filters = []): array
    {
        return $this->allWithScope($context, $averages, $filters)['data'];
    }

    public function teamAverages(SportContext $context, BasketballTeamSeasonAveragesService $averages, int $teamId, array $filters = []): ?array
    {
        return $this->teamWithScope($context, $averages, $teamId, $filters)['data'];
    }

    public function allWithScope(SportContext $context, BasketballTeamSeasonAveragesService $averages, array $filters = []): array
    {
        return $this->aggregate($context, $averages, $filters);
    }

    public function teamWithScope(SportContext $context, BasketballTeamSeasonAveragesService $averages, int $teamId, array $filters = []): array
    {
        $result = $this->aggregate($context, $averages, $filters, $teamId);
        $result['data'] = $result['data'][0] ?? null;

        return $result;
    }

    private function aggregate(SportContext $context, BasketballTeamSeasonAveragesService $averages, array $filters, ?int $teamId = null): array
    {
        $model = $this->teamStatModel($context);
        if ($context->slug !== 'mlb') {
            $result = $averages->aggregate($model, $filters, $teamId, $context->slug === 'nba' && $teamId !== null);
            $result['data'] = $result['data']->all();

            return $result;
        }
        $scope = app(CompletedTeamStatScope::class)->resolve($model, $filters, $teamId);
        $table = $scope['table'];
        $grammar = $scope['query']->getQuery()->getGrammar();
        $select = [$grammar->wrap("{$table}.team_id").' as team_id', 'COUNT(*) as games_played'];
        foreach (self::MLB_FIELDS as $column => $alias) {
            $select[] = 'AVG('.$grammar->wrap("{$table}.{$column}").") as {$alias}";
            $select[] = 'COUNT('.$grammar->wrap("{$table}.{$column}").") as samples_{$alias}";
        }
        $rows = $scope['query']->selectRaw(implode(', ', $select))->groupBy("{$table}.team_id")->orderBy("{$table}.team_id")->get();
        $data = $rows->map(function (object $row) use ($scope): array {
            $result = ['team_id' => (int) $row->team_id, 'games_played' => (int) $row->games_played,
                'season' => $scope['metadata']['season'], 'season_type' => $scope['metadata']['season_type'], 'sample_sizes' => []];
            foreach (self::MLB_FIELDS as $alias) {
                $value = $row->{$alias};
                $result[$alias] = is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
                $result['sample_sizes'][$alias] = (int) $row->{'samples_'.$alias};
            }

            return $result;
        })->all();

        return ['data' => $data, 'scope' => $scope['metadata']];
    }

    /** @return class-string<Model> */
    public function teamStatModel(SportContext $context): string
    {
        $model = $context->models['team_stat'] ?? null;
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            abort(404, "Team stat season averages are not available for {$context->slug}.");
        }

        return $model;
    }
}
