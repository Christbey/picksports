<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/** Bounded primary-only state, without eager model-run metadata or artifact bodies. */
final class MlbArtifactCohortState
{
    /** Central admission for direct registration as well as staged completions. */
    public function assertAddition(Connection $connection, array $metrics): void
    {
        $members = $this->read($connection);
        if ($members->count() >= 1000) {
            throw new RuntimeException('MLB artifact insertion would exceed its reviewed cohort count.');
        }
        $this->assertMetrics($members, $metrics);
        MlbArtifactCohortSerializer::assertOwned($connection);
    }

    private function assertMetrics(Collection $members, array $metrics): void
    {
        if ($metrics !== [] && array_is_list($metrics)) {
            throw new RuntimeException('MLB artifact cohort metrics must be a map.');
        }
        $raw = MlbTabularJsonBudget::encode($metrics, 262144, 50000, 24);
        $bytes = strlen($raw);
        $tokens = MlbTabularJsonBudget::inspect($raw, 262144, 50000, 24);
        foreach ($members as $member) {
            $existing = $member->getRawOriginal('metrics');
            if ($existing !== null) {
                $bytes += strlen($existing);
                $tokens += MlbTabularJsonBudget::inspect($existing, 262144, 50000, 24);
            }
            if ($bytes > 2097152 || $tokens > 50000) {
                throw new RuntimeException('MLB artifact insertion would exceed its reviewed cohort metrics budget.');
            }
        }
    }

    /** Pure prospective budget over an already admitted current cohort; no repeated per-member SQL reads. */
    public function assertReplacements(Collection $members, array $replacements): void
    {
        if ($members->count() > 1000 || count($replacements) > 1000) {
            throw new RuntimeException('MLB cohort replacements exceed the reviewed row budget.');
        }
        $bytes = $tokens = 0;
        foreach ($members as $member) {
            $raw = array_key_exists($member->id, $replacements)
                ? MlbTabularJsonBudget::encode($replacements[$member->id], 262144, 50000, 24)
                : $member->getRawOriginal('metrics');
            if ($raw === null) {
                continue;
            }
            $bytes += strlen($raw);
            $tokens += MlbTabularJsonBudget::inspect($raw, 262144, 50000, 24);
            if ($bytes > 2097152 || $tokens > 50000) {
                throw new RuntimeException('MLB artifact mutation exceeds its prospective cohort metrics budget.');
            }
        }
        if (array_diff(array_keys($replacements), $members->modelKeys()) !== []) {
            throw new RuntimeException('MLB cohort replacement cannot introduce an undiscovered member.');
        }
    }

    /** Compiled shadow-family predicate, inside the already admitted complete sport range. */
    public function shadowSelectionPeerIds(Connection $connection, Collection $members): array
    {
        MlbArtifactCohortSerializer::assertOwned($connection);
        $ids = $connection->table('model_artifacts')->useWritePdo()->where('sport', 'mlb')
            ->where('model_type', 'mlb_tabular_bundle')->whereIn('status', ['challenger', 'promotion_eligible'])
            ->orderBy('id')->limit(1001)->lockForUpdate()->pluck('id')->all();
        if (count($ids) > 1000 || array_diff($ids, $members->modelKeys()) !== []) {
            throw new RuntimeException('MLB shadow selection predicate left its admitted current range.');
        }
        MlbArtifactCohortSerializer::assertOwned($connection);

        return array_fill_keys($ids, true);
    }

    public function read(Connection $connection): Collection
    {
        MlbArtifactCohortSerializer::assertOwned($connection);
        $length = $connection->getDriverName() === 'mysql'
            ? 'OCTET_LENGTH(metrics)' : 'length(CAST(metrics AS BLOB))';
        $scalarSelection = $invalid = [];
        foreach (['id' => 36, 'training_run_id' => 36, 'model_type' => 96, 'status' => 32,
            'created_at' => 32, 'updated_at' => 32, 'promoted_at' => 32] as $field => $maximum) {
            $fieldLength = $connection->getDriverName() === 'mysql'
                ? 'OCTET_LENGTH('.$field.')' : 'length(CAST('.$field.' AS BLOB))';
            $scalarSelection[] = 'CASE WHEN '.$fieldLength.' <= '.$maximum.' THEN '.$field.' ELSE NULL END AS '.$field;
            $invalid[] = 'CASE WHEN '.$field.' IS NULL OR '.$fieldLength.' <= '.$maximum.' THEN 0 ELSE 1 END';
        }
        $scalarSql = implode(', ', $scalarSelection).', ('.implode(' + ', $invalid).') AS scalar_invalid';
        $base = $connection->table('model_artifacts')->useWritePdo()->where('sport', 'mlb');
        $inventory = (clone $base)->selectRaw($scalarSql)
            ->selectRaw('COALESCE('.$length.', 0) AS metrics_bytes')->orderBy('id')->limit(1001)->lockForUpdate()->get();
        if ($inventory->count() > 1000) {
            throw new RuntimeException('MLB artifact cohort exceeds its 1000-row read bound.');
        }
        $bytes = 0;
        foreach ($inventory as $row) {
            if ((int) $row->scalar_invalid !== 0 || $row->id === null || $row->training_run_id === null || $row->model_type === null || $row->status === null) {
                throw new RuntimeException('MLB artifact cohort identity exceeds its scalar bound.');
            }
            foreach (['id' => 36, 'training_run_id' => 36, 'model_type' => 96, 'status' => 32,
                'created_at' => 32, 'updated_at' => 32, 'promoted_at' => 32] as $field => $maximum) {
                if ($row->$field !== null && (! is_string($row->$field) || strlen($row->$field) > $maximum)) {
                    throw new RuntimeException('MLB artifact cohort identity exceeds its scalar bound.');
                }
            }
            $size = (int) $row->metrics_bytes;
            $bytes += $size;
            if ($size < 0 || $size > 262144 || $bytes > 2097152) {
                throw new RuntimeException('MLB artifact cohort metrics exceed their read byte budget.');
            }
        }
        $result = new Collection;
        $bytes = $tokens = 0;
        foreach ($inventory->chunk(7) as $chunk) {
            $rows = (clone $base)->whereIn('id', $chunk->pluck('id')->all())
                ->selectRaw($scalarSql)
                ->selectRaw('COALESCE('.$length.', 0) AS metrics_bytes')
                ->selectRaw('CASE WHEN '.$length.' <= 262144 THEN metrics ELSE NULL END AS metrics_body')
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($chunk as $expected) {
                $row = $rows[$expected->id] ?? null;
                if ($row === null || (int) $row->scalar_invalid !== 0) {
                    throw new RuntimeException('MLB artifact cohort membership changed during its bounded read.');
                }
                foreach (['training_run_id', 'model_type', 'status', 'created_at', 'updated_at', 'promoted_at', 'metrics_bytes'] as $field) {
                    if ((string) ($row->$field ?? '') !== (string) ($expected->$field ?? '')) {
                        throw new RuntimeException('MLB artifact cohort state changed during its bounded read.');
                    }
                }
                $raw = $row->metrics_body;
                if ($raw === null && (int) $row->metrics_bytes !== 0) {
                    throw new RuntimeException('MLB artifact cohort metrics grew beyond their read bound.');
                }
                if ($raw !== null) {
                    $bytes += strlen($raw);
                    if ($bytes > 2097152) {
                        throw new RuntimeException('MLB artifact cohort current metrics exceed their total byte budget.');
                    }
                    $tokens += MlbTabularJsonBudget::inspect($raw, 262144, 50000, 24);
                    if ($tokens > 50000) {
                        throw new RuntimeException('MLB artifact cohort metrics exceed their total token budget.');
                    }
                    $decoded = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
                    if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                        throw new RuntimeException('MLB artifact cohort metrics must be a JSON map.');
                    }
                }
                $attributes = (array) $row;
                unset($attributes['metrics_bytes'], $attributes['metrics_body'], $attributes['scalar_invalid']);
                $attributes['sport'] = 'mlb';
                $attributes['metrics'] = $raw;
                $model = (new ModelArtifact)->newFromBuilder($attributes, $connection->getName());
                $result->push($model);
            }
        }

        MlbArtifactCohortSerializer::assertOwned($connection);

        return $result;
    }
}
