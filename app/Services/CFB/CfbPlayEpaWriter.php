<?php

namespace App\Services\CFB;

use App\Models\CFB\Play;

/** Update existing accepted rows only; never insert a synthetic play during a rebuild. */
class CfbPlayEpaWriter
{
    public function write(int $gameId, string $sourceRevision, array $rows): void
    {
        $model = new Play;
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($model->getTable());
        $id = $grammar->wrap('id');
        foreach (array_chunk($rows, 50) as $chunk) {
            $assignments = $bindings = [];
            foreach (['is_epa_eligible', 'expected_points_before', 'expected_points_after', 'true_epa', 'epa_calculated_at'] as $field) {
                $column = $grammar->wrap($field);
                $case = "CASE {$id}";
                foreach ($chunk as $row) {
                    $case .= ' WHEN ? THEN ?';
                    $bindings[] = (int) $row['id'];
                    $bindings[] = $row[$field];
                }
                $assignments[] = "{$column} = {$case} ELSE {$column} END";
            }
            $assignments[] = $grammar->wrap('updated_at').' = ?';
            $bindings[] = now();
            $bindings[] = $gameId;
            $bindings[] = $sourceRevision;
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($chunk as $row) {
                $bindings[] = (int) $row['id'];
            }
            $where = $grammar->wrap('game_id').' = ? AND '.$grammar->wrap('source_revision').' = ? AND '.$id." IN ({$placeholders})";
            $connection->update("UPDATE {$table} SET ".implode(', ', $assignments).' WHERE '.$where, $bindings);
        }
    }
}
