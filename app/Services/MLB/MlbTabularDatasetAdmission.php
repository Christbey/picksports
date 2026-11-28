<?php

namespace App\Services\MLB;

use Illuminate\Support\Str;
use RuntimeException;

/** Reconstructs CSV cells from current protected evidence; a supplied manifest cannot grant itself trust. */
final class MlbTabularDatasetAdmission
{
    public function __construct(private readonly MlbTabularSnapshotReader $reader, private readonly MlbTabularDatasetExporter $exporter, private readonly MlbTabularDatasetProjector $projector) {}

    public function inspect(string $path): array
    {
        $proof = MlbTabularJsonBudget::decode($this->boundedFile($path.'.manifest.json', MlbTabularDatasetExporter::MAX_MANIFEST_BYTES), MlbTabularDatasetExporter::MAX_MANIFEST_BYTES, 100000);
        $this->assertProof($proof);
        $handle = $this->open($path);
        try {
            $state = fstat($handle);
            if ($state === false || $state['size'] !== $proof['dataset_bytes'] || $state['size'] > MlbTabularDatasetExporter::MAX_CSV_BYTES) {
                throw new RuntimeException('MLB training dataset size does not match its bounded proof.');
            }
            $hash = hash_init('sha256');
            $read = 0;
            while (! feof($handle)) {
                $chunk = fread($handle, min(65536, MlbTabularDatasetExporter::MAX_CSV_BYTES + 1 - $read));
                if ($chunk === false || ($chunk === '' && ! feof($handle))) {
                    throw new RuntimeException('MLB training dataset stream failed.');
                }
                $read += strlen($chunk);
                if ($read > MlbTabularDatasetExporter::MAX_CSV_BYTES) {
                    throw new RuntimeException('MLB training dataset grew beyond its byte budget.');
                }
                hash_update($hash, $chunk);
            }
            if (! hash_equals($proof['dataset_sha256'], hash_final($hash)) || $read !== $proof['dataset_bytes'] || ! rewind($handle)) {
                throw new RuntimeException('MLB training dataset hash/size changed or is not rewindable.');
            }
            $header = $this->record($handle);
            if ($header !== $proof['headers']) {
                throw new RuntimeException('MLB training dataset headers disagree with the proof.');
            }
            $headers = [];
            $bytes = $tokens = $rows = 0;
            $base = $this->reader->query($proof['seasons']);
            foreach ($this->exporter->rows($base, $proof['references'], $proof['include_metadata'], false, $bytes, $tokens) as [$expected, $ref]) {
                $claimed = $proof['references'][$rows] ?? null;
                if ($expected === null || $claimed === null || $this->projector->stableHash($claimed) !== $this->projector->stableHash($ref)) {
                    throw new RuntimeException('MLB dataset source, target or current start context is unqualified or changed.');
                }
                foreach (array_keys($expected) as $name) {
                    $headers[$name] = true;
                }
                $expected['dataset_schema_hash'] = $proof['dataset_schema_hash'];
                $actual = $this->record($handle);
                $expectedCells = array_map(fn ($name) => MlbTabularDatasetExporter::cell($expected[$name] ?? null), $header);
                if ($actual !== $expectedCells) {
                    throw new RuntimeException('MLB dataset values do not match their current protected source/target evidence.');
                }
                $rows++;
            }
            $schemaKeys = array_keys($headers);
            sort($schemaKeys);
            if ($rows !== $proof['rows'] || $this->record($handle) !== null
                || $proof['dataset_schema_hash'] !== $this->projector->stableHash($schemaKeys)
                || $header !== [...array_keys($headers), 'dataset_schema_hash']) {
                throw new RuntimeException('MLB dataset rows, extra columns or reconstructed schema do not match.');
            }

            return $proof;
        } finally {
            fclose($handle);
        }
    }

    public function assertProof(mixed $proof): void
    {
        if (! is_array($proof) || ($proof['schema'] ?? null) !== MlbTabularDatasetExporter::POLICY
            || ($proof['research_only'] ?? null) !== false || ($proof['complete_scan'] ?? null) !== true || ($proof['limit_hit'] ?? null) !== false
            || ! is_bool($proof['include_metadata'] ?? null) || ! is_array($proof['references'] ?? null)
            || ! is_int($proof['rows'] ?? null) || $proof['rows'] < 1 || $proof['rows'] > MlbTabularSnapshotReader::MAX_GAMES
            || count($proof['references']) !== $proof['rows'] || ! array_is_list($proof['references'])
            || ! is_int($proof['dataset_bytes'] ?? null) || $proof['dataset_bytes'] < 1 || $proof['dataset_bytes'] > MlbTabularDatasetExporter::MAX_CSV_BYTES
            || ! $this->sha($proof['dataset_sha256'] ?? null) || ! $this->sha($proof['dataset_schema_hash'] ?? null)
            || ! is_array($proof['headers'] ?? null) || ! array_is_list($proof['headers']) || count($proof['headers']) > MlbTabularDatasetExporter::MAX_HEADERS + 1
            || ! is_array($proof['seasons'] ?? null) || ! array_is_list($proof['seasons']) || count($proof['seasons']) > 20) {
            throw new RuntimeException('MLB tabular training requires a complete nonresearch owned capture proof.');
        }
        $seen = $games = [];
        foreach ($proof['headers'] as $name) {
            if (! is_string($name) || strlen($name) > 256 || isset($seen[$name])) {
                throw new RuntimeException('MLB proof headers are invalid or duplicated.');
            }
            $seen[$name] = true;
        }
        $seen = [];
        foreach ($proof['seasons'] as $season) {
            if (! is_int($season) || $season < 1000 || $season > 9999) {
                throw new RuntimeException('MLB proof season is invalid.');
            }
        }
        foreach ($proof['references'] as $ref) {
            if (! is_array($ref) || ! is_int($ref['snapshot_id'] ?? null) || $ref['snapshot_id'] < 1
                || ! is_int($ref['evaluation_id'] ?? null) || $ref['evaluation_id'] < 1
                || ! is_int($ref['game_id'] ?? null) || $ref['game_id'] < 1
                || ! is_int($ref['prediction_id'] ?? null) || $ref['prediction_id'] < 1
                || ! is_string($ref['model_run_id'] ?? null) || ! Str::isUuid($ref['model_run_id'])
                || ! array_key_exists('quote_id', $ref) || ($ref['quote_id'] !== null && (! is_int($ref['quote_id']) || $ref['quote_id'] < 1))
                || isset($seen[$ref['snapshot_id']]) || isset($games[$ref['game_id']]) || ($ref['qualified'] ?? null) !== true
                || ! $this->sha($ref['row_hash'] ?? null) || ! $this->sha($ref['source_hash'] ?? null)
                || ! $this->sha($ref['game_context_hash'] ?? null) || ! $this->sha($ref['grade_context_hash'] ?? null) || ! $this->sha($ref['capture_marker_hash'] ?? null)) {
                throw new RuntimeException('MLB proof source references are invalid, duplicated or unqualified.');
            }
            $seen[$ref['snapshot_id']] = true;
            $games[$ref['game_id']] = true;
        }
    }

    /** Bounded lexical CSV scan before str_getcsv can allocate fields. Doubled quotes toggle twice. */
    private function record($handle): ?array
    {
        $raw = '';
        $quoted = false;
        $fields = 1;
        $fieldBytes = 0;
        while (($char = fgetc($handle)) !== false) {
            $raw .= $char;
            $fieldBytes++;
            if (strlen($raw) > MlbTabularSnapshotReader::MAX_BATCH_BYTES || $fieldBytes > 2 * MlbTabularDatasetExporter::MAX_CELL + 2) {
                throw new RuntimeException('MLB CSV row/field byte budget exceeded before parsing.');
            }
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif ($char === ',' && ! $quoted) {
                if (++$fields > MlbTabularDatasetExporter::MAX_HEADERS + 1) {
                    throw new RuntimeException('MLB CSV field count budget exceeded before parsing.');
                }
                $fieldBytes = 0;
            } elseif ($char === "\n" && ! $quoted) {
                return str_getcsv($raw, ',', '"', '');
            }
        }
        if (! feof($handle) || $quoted) {
            throw new RuntimeException('MLB CSV stream is incomplete or malformed.');
        }

        return $raw === '' ? null : str_getcsv($raw, ',', '"', '');
    }

    private function boundedFile(string $path, int $maximum): string
    {
        $handle = $this->open($path);
        try {
            $raw = stream_get_contents($handle, $maximum + 1);
            if ($raw === false || strlen($raw) > $maximum || ! feof($handle)) {
                throw new RuntimeException('MLB proof exceeds its bounded read budget.');
            }

            return $raw;
        } finally {
            fclose($handle);
        }
    }

    private function open(string $path)
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('MLB exact dataset/proof must be an available regular file.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open MLB exact dataset/proof.');
        }

        return $handle;
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
