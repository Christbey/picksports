<?php

namespace App\Services\CollegeFootballData\Fpi;

use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\CfbdTeamMapping;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\Json\JsonAllocationBudget;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final class FpiSourceInventory
{
    public function capture(Connection $connection, int $season, bool $lock): array
    {
        $used = 0;
        $teams = $this->rows($connection, 'cfb_teams', ['id', 'cfbd_team_id'],
            ['school', 'abbreviation', 'division', 'conference', 'espn_id'], 1024, $lock, [], ['created_at' => 32], $used);
        $mappings = $this->rows($connection, 'cfbd_team_mappings', ['id', 'cfbd_team_id'],
            ['sport', 'cfbd_team_name', 'espn_team_name', 'cfbd_abbreviation'], 4096, $lock, ['sport' => ['cfb', 'americanfootball_ncaaf']], ['alternate_names' => 8192, 'created_at' => 32, 'updated_at' => 32], $used);
        $affiliations = $this->rows($connection, 'cfb_team_season_affiliations', ['id', 'team_id', 'season'],
            ['subdivision', 'conference', 'division', 'source'], 1024, $lock, ['season' => $season], ['created_at' => 32, 'updated_at' => 32], $used);
        foreach ($mappings as $row) {
            if (! in_array($row['sport'], ['cfb', 'americanfootball_ncaaf'], true)) {
                throw new RuntimeException('FPI mapping has a noncanonical football scope.');
            }
            $raw = $row['alternate_names'];
            if ($raw !== null) {
                JsonAllocationBudget::inspect($raw, 8192, 100, 2, 'FPI mapping aliases');
                $aliases = json_decode($raw, true, 3, JSON_THROW_ON_ERROR);
                if (! is_array($aliases) || ! array_is_list($aliases) || count($aliases) > 16) {
                    throw new RuntimeException('FPI mapping aliases require at most sixteen plain names.');
                }
                foreach ($aliases as $alias) {
                    if (! is_string($alias) || strlen($alias) > 256) {
                        throw new RuntimeException('FPI mapping alias is outside the name budget.');
                    }
                }
            }
        }
        $configuration = config('cfb.season_affiliations', []);
        $json = json_encode($configuration, JSON_THROW_ON_ERROR);
        JsonAllocationBudget::inspect($json, 262144, 20000, 16, 'FPI affiliation configuration');
        $provider = config('services.collegefootballdata.base_url', 'https://api.collegefootballdata.com');
        if (! is_string($provider) || $provider === '' || strlen($provider) > 2048) {
            throw new RuntimeException('FPI provider endpoint configuration is outside its scalar budget.');
        }
        $data = ['provider_base_url' => rtrim($provider, '/'), 'teams' => $teams, 'mappings' => $mappings, 'affiliations' => $affiliations,
            'configuration' => $configuration, 'division_policy' => config('cfb.teams')];
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 2097152) {
            throw new RuntimeException('FPI identity inventory exceeds the aggregate budget.');
        }

        return ['hash' => app(CanonicalPayloadHasher::class)->hash($data), 'data' => $data];
    }

    private function rows(Connection $connection, string $table, array $scalars, array $texts, int $maximumRows,
        bool $lock, array $filters, array $largeTexts, int &$used): array
    {
        $query = $connection->table($table)->useWritePdo()->orderBy('id')->limit(128);
        foreach ($filters as $column => $value) {
            is_array($value) ? $query->whereIn($column, $value) : $query->where($column, $value);
        }
        if ($connection->getDriverName() === 'mysql') {
            $query->forceIndex(match ($table) {
                'cfbd_team_mappings' => 'cfbd_team_mappings_sport_index',
                'cfb_team_season_affiliations' => 'cfb_team_season_affiliations_season_subdivision_index',
                default => 'PRIMARY',
            });
        }
        $grammar = $connection->getQueryGrammar();
        $bad = [];
        foreach ($scalars as $column) {
            $largeTexts[$column] = 32;
        }
        foreach ($texts as $column) {
            $largeTexts[$column] = 256;
        }
        foreach ($largeTexts as $column => $bytes) {
            $quoted = $grammar->wrap($column);
            $length = $connection->getDriverName() === 'mysql' ? "OCTET_LENGTH({$quoted})" : "length(CAST({$quoted} AS BLOB))";
            $bad[] = "COALESCE({$length},0) > {$bytes}";
        }
        $predicate = $bad === [] ? '0=1' : implode(' OR ', $bad);
        $query->selectRaw("CASE WHEN {$predicate} THEN 1 ELSE 0 END AS invalid_source_body");
        foreach ($largeTexts as $column => $bytes) {
            $quoted = $grammar->wrap($column);
            $query->selectRaw("CASE WHEN {$predicate} THEN NULL ELSE {$quoted} END AS {$quoted}");
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $all = [];
        $lastId = 0;
        do {
            $page = (clone $query)->where('id', '>', $lastId)->get();
            foreach ($page as $row) {
                if ((int) $row->invalid_source_body !== 0 || count($all) >= $maximumRows) {
                    throw new RuntimeException('FPI source row or field budget exceeded.');
                }
                $values = (array) $row;
                unset($values['invalid_source_body']);
                foreach ($scalars as $field) {
                    $value = $values[$field];
                    if ($field === 'cfbd_team_id' && $value === null) {
                        continue;
                    }
                    if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[1-9][0-9]*\z/', (string) $value)
                        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        throw new RuntimeException('FPI raw source identity is not a positive canonical integer.');
                    }
                }
                $used += strlen(json_encode($values, JSON_THROW_ON_ERROR)) + 1;
                if ($used > 2097152) {
                    throw new RuntimeException('FPI source inventory exceeds its aggregate byte budget.');
                }
                $all[] = $values;
                $lastId = (int) $values['id'];
            }
        } while ($page->count() === 128);

        return $all;
    }

    /** Literal numeric target projection; no future JSON/body column is implicitly fetched. */
    public static function ratingProjection(Builder $query): Builder
    {
        $fields = ['id', 'team_id', 'season', 'week', 'fpi', 'fpi_rank', 'offense', 'defense', 'special_teams', 'created_at', 'updated_at'];
        $connection = $query->getConnection();
        $grammar = $query->getGrammar();
        $bad = [];
        foreach ($fields as $field) {
            $quoted = $grammar->wrap($field);
            $length = $connection->getDriverName() === 'mysql' ? "OCTET_LENGTH(CAST({$quoted} AS CHAR))" : "length(CAST({$quoted} AS BLOB))";
            $bad[] = "COALESCE({$length},0)>64";
        }
        $predicate = implode(' OR ', $bad);
        $query->selectRaw("CASE WHEN {$predicate} THEN 1 ELSE 0 END AS invalid_rating_body");
        foreach ($fields as $field) {
            $quoted = $grammar->wrap($field);
            $query->selectRaw("CASE WHEN {$predicate} THEN NULL ELSE {$quoted} END AS {$quoted}");
        }

        return $query;
    }

    public function expectedAfterBindings(array $data, array $bindings): array
    {
        // Original scalar inventory plus the explicit pre-save binding map; never mutable model state.
        $copy = json_decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 32, JSON_THROW_ON_ERROR);
        if (count($bindings) > 1024) {
            throw new RuntimeException('FPI intended binding map exceeds its declared inventory.');
        }
        $known = array_fill_keys(array_column($copy['teams'], 'id'), true);
        foreach ($bindings as $id => $value) {
            if (! isset($known[$id]) || ! is_int($id) || $id < 1 || ! is_int($value) || $value < 1) {
                throw new RuntimeException('FPI intended binding is outside its original declared team identities.');
            }
        }
        foreach ($copy['teams'] as $index => $row) {
            if (array_key_exists($row['id'], $bindings)) {
                $copy['teams'][$index]['cfbd_team_id'] = $bindings[$row['id']];
            }
        }

        return $copy;
    }

    public function models(Connection $connection, array $inventory): array
    {
        $hydrate = function (string $class, array $rows) use ($connection) {
            $model = (new $class)->setConnection($connection->getName());
            if ($model->getConnection() !== $connection) {
                throw new RuntimeException('FPI source connection changed.');
            }

            return $model->newCollection(array_map(fn ($row) => $model->newFromBuilder($row, $connection->getName()), $rows));
        };
        $teams = $hydrate(Team::class, $inventory['teams']);
        $affiliations = $hydrate(TeamSeasonAffiliation::class, $inventory['affiliations'])->groupBy('team_id');
        foreach ($teams as $team) {
            $team->setRelation('seasonAffiliations', $affiliations->get($team->id, (new TeamSeasonAffiliation)->newCollection()));
        }

        return [$teams, $hydrate(CfbdTeamMapping::class, $inventory['mappings'])];
    }
}
