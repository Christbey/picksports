<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;

/** Update existing accepted rows only; never insert a synthetic play during a rebuild. */
class CfbPlayEpaWriter
{
    public function write(int $gameId, string $sourceRevision, array $rows, int $acceptanceReceiptId, ?int $boxscoreAcceptanceReceiptId): void
    {
        $model = new Play;
        $connection = $model->getConnection();
        $connection->transaction(function () use ($connection, $model, $gameId, $sourceRevision, $rows, $acceptanceReceiptId, $boxscoreAcceptanceReceiptId): void {
            $game = Game::on($connection->getName())->whereKey($gameId)->lockForUpdate()->firstOrFail();
            $check = app(CfbDataReadiness::class)->acceptedCheck($game, 'plays');
            $receipt = $check === null ? null : app(CfbSourceAssessmentRecorder::class)->acceptanceReceipt($check);
            $box = app(CfbDataReadiness::class)->acceptedCheck($game, 'boxscore');
            $boxReceipt = $box === null ? null : app(CfbSourceAssessmentRecorder::class)->acceptanceReceipt($box);
            if ($check?->source_hash !== $sourceRevision || $receipt?->id !== $acceptanceReceiptId
                || $boxReceipt?->id !== $boxscoreAcceptanceReceiptId) {
                throw new \DomainException('Accepted CFB play assessment changed before EPA write.');
            }
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
        });
    }
}
