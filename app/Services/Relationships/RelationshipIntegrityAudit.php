<?php

namespace App\Services\Relationships;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Counts integrity exceptions without changing records or exporting row contents. */
final class RelationshipIntegrityAudit
{
    /**
     * @param  array<string, array<string, mixed>>  $inventory
     * @return array<string, array<string, mixed>>
     */
    public function inspect(array $inventory): array
    {
        $tables = [];
        foreach ($inventory as $definition) {
            if (! $definition['table_exists']) {
                continue;
            }
            $table = $definition['table'];
            if (isset($tables[$table])) {
                continue;
            }
            $connection = DB::connection($definition['connection']);
            $checks = ['row_count' => $connection->table($table)->count(), 'foreign_keys' => [], 'unique_indexes' => []];
            $columns = array_column($definition['columns'], 'name');
            foreach ($definition['foreign_keys'] as $foreign) {
                $query = $connection->table($table.' as child');
                foreach ($foreign['columns'] as $column) {
                    $query->whereNotNull('child.'.$column);
                }
                $query->whereNotExists(function (Builder $parent) use ($foreign): void {
                    $parent->selectRaw('1')->from($foreign['foreign_table'].' as parent');
                    foreach ($foreign['columns'] as $index => $column) {
                        $parent->whereColumn('parent.'.$foreign['foreign_columns'][$index], 'child.'.$column);
                    }
                });
                $parentColumns = Schema::connection($definition['connection'])->getColumnListing($foreign['foreign_table']);
                $sharedIdentity = array_values(array_intersect(['sport_event_id', 'sport', 'phase', 'game_id'], $columns, $parentColumns));
                $mismatch = $connection->table($table.' as child')->join($foreign['foreign_table'].' as parent', function ($join) use ($foreign): void {
                    foreach ($foreign['columns'] as $index => $column) {
                        $join->on('child.'.$column, '=', 'parent.'.$foreign['foreign_columns'][$index]);
                    }
                });
                $mismatch->where(function (Builder $query) use ($sharedIdentity): void {
                    foreach ($sharedIdentity as $column) {
                        $query->orWhereColumn('child.'.$column, '!=', 'parent.'.$column);
                    }
                });
                $checks['foreign_keys'][] = [
                    'columns' => $foreign['columns'],
                    'parent_table' => $foreign['foreign_table'],
                    'orphan_count' => $query->count(),
                    'shared_identity_columns' => $sharedIdentity,
                    'lineage_mismatch_count' => $sharedIdentity === [] ? 0 : $mismatch->count(),
                ];
            }
            foreach ($definition['indexes'] as $index) {
                if (! $index['unique']) {
                    continue;
                }
                $groups = $connection->table($table)->select($index['columns'])->groupBy($index['columns'])->havingRaw('COUNT(*) > 1');
                foreach ($index['columns'] as $column) {
                    $groups->whereNotNull($column);
                }
                $checks['unique_indexes'][] = [
                    'name' => $index['name'],
                    'columns' => $index['columns'],
                    'duplicate_group_count' => $connection->query()->fromSub($groups, 'duplicate_groups')->count(),
                ];
            }
            if (in_array('sport_event_id', $columns, true) && $table !== 'sport_events') {
                $query = $connection->table($table.' as child')->join('sport_events as event', 'event.id', '=', 'child.sport_event_id');
                if (in_array('sport', $columns, true)) {
                    $checks['event_sport_mismatch_count'] = $query->whereColumn('child.sport', '!=', 'event.sport')->count();
                } elseif (preg_match('/^(nfl|nba|wnba|cbb|wcbb|cfb|mlb)_/', $table, $match) === 1) {
                    $checks['event_sport_mismatch_count'] = $query->where('event.sport', '!=', $match[1])->count();
                }
                $checks['null_event_link_count'] = $connection->table($table)->whereNull('sport_event_id')->count();
            }
            if (count(array_intersect(['sport', 'game_table', 'game_id'], $columns)) === 3) {
                $checks['dynamic_game_references'] = $this->dynamicReferences($definition['connection'], $table, 'game');
            }
            if (count(array_intersect(['sport', 'prediction_table', 'prediction_id'], $columns)) === 3) {
                $checks['dynamic_prediction_references'] = $this->dynamicReferences($definition['connection'], $table, 'prediction');
            }
            $tables[$table] = $checks;
        }
        ksort($tables);

        return $tables;
    }

    /** @return array{invalid_type_count:int,orphan_count:int} */
    private function dynamicReferences(?string $connectionName, string $table, string $kind): array
    {
        $connection = DB::connection($connectionName);
        $tableColumn = $kind.'_table';
        $idColumn = $kind.'_id';
        $valid = $connection->table($table)->whereNotNull($idColumn)->where(function (Builder $query) use ($kind, $tableColumn): void {
            foreach (['nfl', 'nba', 'wnba', 'mlb', 'cfb', 'cbb', 'wcbb'] as $sport) {
                $query->orWhere(fn (Builder $row) => $row->where('sport', $sport)->whereIn($tableColumn, $kind === 'game' ? [$sport.'_games'] : [$sport.'_predictions', 'predictions']));
            }
        });
        $invalid = $connection->table($table)->whereNotNull($idColumn)->count() - $valid->count();
        $orphans = 0;
        foreach (['nfl', 'nba', 'wnba', 'mlb', 'cfb', 'cbb', 'wcbb'] as $sport) {
            $targets = $kind === 'game' ? [$sport.'_games'] : [$sport.'_predictions', 'predictions'];
            foreach ($targets as $target) {
                $orphans += $connection->table($table.' as child')->where('child.sport', $sport)
                    ->where('child.'.$tableColumn, $target)->whereNotNull('child.'.$idColumn)
                    ->whereNotExists(function (Builder $query) use ($kind, $target, $sport, $idColumn): void {
                        $query->selectRaw('1')->from($target.' as parent')->whereColumn('parent.id', 'child.'.$idColumn);
                        if ($kind === 'prediction' && $target === 'predictions') {
                            $query->where('parent.sport', $sport);
                        }
                    })->count();
            }
        }

        return ['invalid_type_count' => $invalid, 'orphan_count' => $orphans];
    }
}
