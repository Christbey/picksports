<?php

namespace App\Services\Sports\Elo;

use App\Exceptions\EloAdmissionRefused;
use App\Models\SportEloWriterGate;
use Illuminate\Database\Connection;

/** Durable admission cannot be undone by changing a process-local configuration flag. */
class EloAdmissionMode
{
    public function managed(string $sport, Connection $connection): bool
    {
        if (! in_array($sport, SportEloWriterGate::SPORTS, true)) {
            return false;
        }
        if (config('elo_integrity.enabled', false)) {
            return true;
        }
        $schema = $connection->getSchemaBuilder();
        $existsSql = $schema->getConnection()->getSchemaGrammar()->compileTableExists(null, $connection->getTablePrefix().'sport_elo_writer_gates');
        if (! is_string($existsSql)) {
            throw new \RuntimeException('Unsupported authoritative Elo admission metadata connection.');
        }
        if (! $connection->scalar($existsSql, [], false)) {
            return false; // Untouched schema, not a claim of managed-history validity.
        }
        $row = $connection->table('sport_elo_writer_gates as gates')
            ->leftJoin('sport_elo_season_initializations as admissions', 'admissions.sport', '=', 'gates.sport')
            ->where('gates.sport', $sport)->orderBy('admissions.id')->lockForUpdate()->limit(1)
            ->first(['gates.mode', 'gates.calculation_version', 'gates.configuration_hash', 'gates.generation', 'admissions.id as admission_id']);
        if ($row !== null && ($row->mode !== 'disabled' || $row->calculation_version !== null || $row->configuration_hash !== null
            || (int) $row->generation !== 0 || $row->admission_id !== null)) {
            throw new EloAdmissionRefused('durable_elo_admission_disabled', [], []);
        }

        return false;
    }
}
