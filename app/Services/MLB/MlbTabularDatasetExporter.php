<?php

namespace App\Services\MLB;

use App\DataTransferObjects\MLB\TrainingExportSnapshot;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Support\Str;
use RuntimeException;

/** A bounded stored-evidence export; a sidecar is evidence to revalidate, never permission by itself. */
final class MlbTabularDatasetExporter
{
    public const POLICY = 'mlb-tabular-observed-capture-v1';

    public const MAX_HEADERS = 2048;

    public const MAX_CELL = 262144;

    public const MAX_CSV_BYTES = 67108864;

    public const MAX_MANIFEST_BYTES = 2097152;

    public function __construct(private readonly MlbTabularSnapshotReader $reader, private readonly MlbTabularDatasetProjector $projector, private readonly MlbSnapshotCaptureIntegrity $integrity) {}

    public function export(string $path, array $seasons, int $limit, bool $includeMetadata, bool $research): array
    {
        $base = $this->reader->query($seasons);
        $scope = $this->reader->assertScope($base);
        $winners = [];
        $ignoredLegacySnapshots = 0;
        $bytes = $tokens = 0;
        foreach ($this->reader->batches($base) as $batch) {
            foreach ($this->reader->hydrate($base, $batch, $bytes, $tokens, resolveMetadata: ! $research) as $snapshot) {
                // Unmarked history is outside the owned protocol. Any claimed marker,
                // including a malformed one, remains a candidate and cannot trigger an older fallback.
                if (! $research && (! is_array($snapshot->model_metadata) || ! array_key_exists('mlb_capture_integrity', $snapshot->model_metadata))) {
                    $ignoredLegacySnapshots++;

                    continue;
                }
                $key = (int) $snapshot->game_id;
                $rank = [$snapshot->features_available_at?->getTimestamp() ?? PHP_INT_MIN, $snapshot->generated_at?->getTimestamp() ?? PHP_INT_MIN, (int) $snapshot->id];
                if (! isset($winners[$key]) || $rank > $winners[$key]['rank']) {
                    $winners[$key] = ['snapshot_id' => (int) $snapshot->id, 'evaluation_id' => (int) $snapshot->evaluation_id,
                        'game_id' => $key, 'rank' => $rank, 'start' => $snapshot->game_start_at?->getTimestamp() ?? $rank[1]];
                }
                if (count($winners) > MlbTabularSnapshotReader::MAX_GAMES) {
                    throw new RuntimeException('MLB tabular game budget exceeded; narrow the explicit seasons.');
                }
            }
        }
        usort($winners, fn ($a, $b) => [$a['start'], $a['game_id']] <=> [$b['start'], $b['game_id']]);
        $headers = [];
        $refs = [];
        $declined = 0;
        $limitHit = false;
        $bytes = $tokens = 0;
        foreach ($this->rows($base, $winners, $includeMetadata, $research, $bytes, $tokens) as [$row, $ref]) {
            if ($row === null) {
                $declined++;

                continue;
            }
            if ($limit > 0 && count($refs) >= $limit) {
                $limitHit = true;

                continue;
            }
            foreach (array_keys($row) as $header) {
                if (! is_string($header) || strlen($header) > 256) {
                    throw new RuntimeException('MLB CSV header is invalid or exceeds its byte budget.');
                }
                $headers[$header] = true;
            }
            if (count($headers) > self::MAX_HEADERS) {
                throw new RuntimeException('MLB CSV header count budget exceeded.');
            }
            $refs[] = $ref;
        }
        if ($refs === []) {
            return ['rows' => 0, 'declined' => $declined, 'ignored_legacy_snapshots' => $ignoredLegacySnapshots];
        }
        $schemaKeys = array_keys($headers);
        sort($schemaKeys);
        $schemaHash = $this->projector->stableHash($schemaKeys);
        $headers['dataset_schema_hash'] = true;
        $headers = array_keys($headers);
        $proof = ['schema' => self::POLICY, 'research_only' => $research, 'complete_scan' => true, 'limit_hit' => $limitHit,
            'selection_scope' => $research ? 'latest_joined_snapshot_per_game_research_only' : 'latest_claimed_capture_per_game_no_invalid_fallback', 'seasons' => $seasons,
            'ignored_legacy_snapshots' => $ignoredLegacySnapshots,
            'include_metadata' => $includeMetadata, 'source_scope' => $scope, 'declined_games' => $declined,
            'dataset_schema_hash' => $schemaHash, 'rows' => count($refs), 'headers' => $headers, 'references' => $refs];
        // Reject expansive proof structures before allocating their decoded representation in consumers.
        $this->boundedManifest($proof);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Unable to create owned MLB export directory.');
        }
        $temp = $path.'.'.bin2hex(random_bytes(16)).'.tmp';
        $manifestTemp = $temp.'.manifest.json';
        $handle = null;
        try {
            $handle = fopen($temp, 'xb');
            if ($handle === false || ! chmod($temp, 0600)) {
                throw new RuntimeException('Unable to create private MLB CSV staging file.');
            }
            $this->putRow($handle, $headers);
            $expected = array_column($refs, null, 'snapshot_id');
            $written = 0;
            $bytes = $tokens = 0;
            foreach ($this->rows($base, $refs, $includeMetadata, $research, $bytes, $tokens) as [$row, $ref]) {
                $old = $expected[$ref['snapshot_id']] ?? null;
                if ($row === null || $old === null || $this->projector->stableHash($old) !== $this->projector->stableHash($ref)) {
                    throw new RuntimeException('MLB source/target/current context changed during export; retry explicitly.');
                }
                $row['dataset_schema_hash'] = $schemaHash;
                $this->putRow($handle, array_map(fn ($header) => self::cell($row[$header] ?? null), $headers));
                if (ftell($handle) > self::MAX_CSV_BYTES) {
                    throw new RuntimeException('MLB CSV total byte budget exceeded.');
                }
                $written++;
            }
            if ($written !== count($refs) || ! fflush($handle)) {
                throw new RuntimeException('MLB CSV write incomplete.');
            }
            $closed = fclose($handle);
            $handle = null;
            if (! $closed) {
                throw new RuntimeException('Unable to close MLB CSV staging file.');
            }
            $proof['dataset_sha256'] = hash_file('sha256', $temp);
            $proof['dataset_bytes'] = filesize($temp);
            $encoded = $this->boundedManifest($proof);
            $manifestHandle = fopen($manifestTemp, 'xb');
            if ($manifestHandle === false) {
                throw new RuntimeException('Unable to create MLB proof staging file.');
            }
            try {
                if (! chmod($manifestTemp, 0600) || fwrite($manifestHandle, $encoded) !== strlen($encoded) || ! fflush($manifestHandle)) {
                    throw new RuntimeException('Unable to write complete MLB proof.');
                }
            } finally {
                fclose($manifestHandle);
            }
            if (! rename($temp, $path) || ! rename($manifestTemp, $path.'.manifest.json')) {
                throw new RuntimeException('MLB export publication incomplete; consumers must require the matching proof hash.');
            }

            return $proof;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            // Only staging files created by this invocation are eligible for cleanup.
            foreach ([$temp, $manifestTemp] as $owned) {
                if (is_file($owned)) {
                    unlink($owned);
                }
            }
        }
    }

    public function rows($base, array $refs, bool $includeMetadata, bool $research, int &$bytes, int &$tokens, bool $current = false): \Generator
    {
        foreach (array_chunk($refs, $current ? 7 : MlbTabularSnapshotReader::BATCH) as $batch) {
            $identities = array_map(fn ($ref) => (object) $ref, $batch);
            $snapshots = $this->reader->hydrate($base, $identities, $bytes, $tokens, $current)->keyBy('id');
            $games = $this->reader->games(array_column($batch, 'game_id'), $current);
            $quoteIds = $snapshots->map(fn ($snapshot) => data_get($snapshot->model_metadata, 'mlb_capture_integrity.quote_proof.snapshot_id'))->filter(fn ($id) => is_int($id) && $id > 0)->all();
            if ($current) {
                foreach ($batch as $expectedRef) {
                    $currentSnapshot = $snapshots[$expectedRef['snapshot_id']] ?? null;
                    if ($currentSnapshot === null || (int) $currentSnapshot->prediction_id !== $expectedRef['prediction_id']
                        || $currentSnapshot->model_run_id !== $expectedRef['model_run_id']
                        || data_get($currentSnapshot->model_metadata, 'mlb_capture_integrity.quote_proof.snapshot_id') !== $expectedRef['quote_id']) {
                        throw new RuntimeException('MLB current source owner changed; no late lock widening is permitted.');
                    }
                }
            }
            $quotes = $this->reader->quotes($quoteIds, $bytes, $tokens, $current);
            foreach ($batch as $ref) {
                $snapshot = $snapshots[$ref['snapshot_id']] ?? null;
                $game = $games[$ref['game_id']] ?? null;
                if ($snapshot === null || $game === null) {
                    throw new RuntimeException('MLB selected source disappeared before projection.');
                }
                $quoteId = data_get($snapshot->model_metadata, 'mlb_capture_integrity.quote_proof.snapshot_id');
                $inspection = $this->integrity->inspect($snapshot, $game, is_int($quoteId) ? $quotes->get($quoteId) : null);
                $target = app(MlbTabularTargetEvidence::class)->inspect($game, $snapshot);
                $row = (! $research && (! $inspection['qualified'] || ! $target['qualified'])) ? null
                    : $this->projector->project(new TrainingExportSnapshot($snapshot, $this->horizon($snapshot)), $includeMetadata);
                $proofRef = ['snapshot_id' => (int) $snapshot->id, 'evaluation_id' => (int) $snapshot->evaluation_id,
                    'game_id' => (int) $snapshot->game_id, 'prediction_id' => (int) $snapshot->prediction_id, 'model_run_id' => $snapshot->model_run_id, 'quote_id' => $quoteId, 'row_hash' => $this->projector->stableHash($row),
                    'game_context_hash' => $game->start_context_hash, 'grade_context_hash' => $target['hash'], 'capture_marker_hash' => data_get($snapshot->model_metadata, 'mlb_capture_integrity.marker_hash'),
                    'source_hash' => $this->sourceHash($snapshot),
                    'qualified' => $inspection['qualified'] && $target['qualified']];
                yield [$row, $proofRef];
            }
        }
    }

    private function sourceHash(PredictionFeatureSnapshot $snapshot): string
    {
        $attributes = $snapshot->getAttributes();
        unset($attributes['batch_bytes'], $attributes['payload_admitted']);
        foreach (['features', 'outputs', 'market_context', 'model_metadata', 'source_timestamps', 'lineage_metadata', 'evaluation_actuals'] as $field) {
            if (is_string($attributes[$field] ?? null)) {
                $attributes[$field] = MlbTabularJsonBudget::decode($attributes[$field], MlbTabularSnapshotReader::MAX_ROW_BYTES);
            }
        }

        return $this->projector->stableHash($attributes);
    }

    public static function cell(mixed $value): string
    {
        $result = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : (is_bool($value) ? ($value ? '1' : '0') : ($value === null ? '' : (string) $value));
        if (strlen($result) > self::MAX_CELL) {
            throw new RuntimeException('MLB CSV cell byte budget exceeded.');
        }
        if (is_array($value)) {
            MlbTabularJsonBudget::inspect($result, self::MAX_CELL);
        }

        return $result;
    }

    private function putRow($handle, array $cells): void
    {
        if (count($cells) > self::MAX_HEADERS + 1 || fputcsv($handle, $cells, ',', '"', '') === false) {
            throw new RuntimeException('Unable to write bounded MLB CSV row.');
        }
    }

    private function boundedManifest(array $proof): string
    {
        $encoded = json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        MlbTabularJsonBudget::inspect($encoded, self::MAX_MANIFEST_BYTES, 100000);

        return $encoded;
    }

    private function horizon(PredictionFeatureSnapshot $snapshot): string
    {
        $horizon = data_get($snapshot->lineage_metadata, 'prediction_horizon') ?? data_get($snapshot->model_metadata, 'prediction_horizon')
            ?? data_get($snapshot->lineage_metadata, 'horizon') ?? data_get($snapshot->model_metadata, 'horizon');

        return is_string($horizon) && trim($horizon) !== ''
            ? (string) Str::of($horizon)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')
            : ($snapshot->availability_status === 'verified_reconstruction' ? 'historical_reconstruction' : 'pregame');
    }
}
