<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowArtifactSelector;
use App\Support\Json\JsonAllocationBudget;
use App\Support\MLB\MlbGameStart;
use RuntimeException;

class MlbPeriodModelRunRegistrar
{
    public function __construct(
        private readonly ModelArtifactRegistry $artifacts,
        private readonly ShadowArtifactSelector $selection,
    ) {}

    public function register(
        string $bundlePath,
        string $manifestPath,
        string $evaluationPath,
        string $datasetPath,
    ): ModelArtifact {
        $manifest = $this->json($manifestPath);
        if (($manifest['execution_format'] ?? null) !== MlbPeriodModelInferenceService::EXECUTION_FORMAT) {
            throw new RuntimeException('MLB period registration requires the current numeric execution format.');
        }
        if (($manifest['timing_policy'] ?? null) !== MlbGameStart::TIMING_POLICY || ($manifest['source_availability_verified'] ?? null) !== true) {
            throw new RuntimeException('MLB period registration requires explicit observed-start and source-availability policy evidence.');
        }
        $this->rejectUnsafeSourcePrefix($datasetPath);

        // A worker declaration or apparently available CSV is not a period-source
        // proof. The current selector supports qualified TABULAR artifacts only.
        // Reject this known unsupported composition before SQL or object storage,
        // rather than committing an orphan training run/artifact before it refuses.
        throw new RuntimeException('MLB period registration is unavailable until a reviewed period source-proof and selection protocol exists.');
    }

    /** Negative admission only: passing this bounded probe never certifies training input. */
    private function rejectUnsafeSourcePrefix(string $path): void
    {
        $bytes = $this->readBounded($path, 65536);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to inspect unsupported period source availability.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! rewind($stream)) {
                throw new RuntimeException('Unable to inspect unsupported period source availability.');
            }
            $headers = fgetcsv($stream, 65537, ',', '"', '');
            if (! is_array($headers) || count($headers) > 128
                || count(array_filter($headers, fn ($header) => is_string($header) && $header !== '' && strlen($header) <= 256)) !== count($headers)
                || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('Period source availability is missing or malformed.');
            }
            $indexes = array_flip($headers);
            if (! isset($indexes['pregame_safe'], $indexes['availability_status'])) {
                throw new RuntimeException('Period source availability is unknown; worker declarations cannot supply it.');
            }
            $count = 0;
            while ($count < 128 && ($row = fgetcsv($stream, 65537, ',', '"', '')) !== false) {
                $count++;
                if (count($row) !== count($headers)
                    || ! in_array($row[$indexes['pregame_safe']], ['1', 'true'], true)
                    || ! in_array($row[$indexes['availability_status']], ['observed_pregame', 'verified_reconstruction'], true)) {
                    throw new RuntimeException('Retrospective or unknown-availability period rows cannot enter registration.');
                }
            }
            if ($count === 0) {
                throw new RuntimeException('Period source availability is empty.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function json(string $path): array
    {
        $bytes = $this->readBounded($path, 2097152);
        JsonAllocationBudget::inspect($bytes, 2097152, 50000, 24, 'MLB unsupported period manifest');
        $decoded = json_decode($bytes, true, 24, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid MLB period manifest.');
        }

        return $decoded;
    }

    /** This bounds only refusal preflight IO, not a supported period dataset capacity policy. */
    private function readBounded(string $path, int $maximum): string
    {
        if (str_contains($path, '://') || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('Period registration preflight requires a regular local file.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read period registration preflight.');
        }
        try {
            $stat = fstat($stream);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Period registration preflight file type changed.');
            }
            $bytes = stream_get_contents($stream, $maximum + 1);
            if ($bytes === false || strlen($bytes) > $maximum || ! feof($stream)) {
                throw new RuntimeException('Unsupported period registration exceeds its bounded refusal preflight.');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }
}
