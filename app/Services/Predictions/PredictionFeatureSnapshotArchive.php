<?php

namespace App\Services\Predictions;

use App\Models\PredictionFeatureSnapshot;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use RuntimeException;

class PredictionFeatureSnapshotArchive
{
    public const PREFETCH_BATCH_SIZE = CompressedJsonPayloadStorage::PREFETCH_BATCH_SIZE;

    public const MARKER = '_picksports_feature_archive';

    private const METADATA_SQL_PATHS = [
        'legacy.spread',
        'analysis_layer.calculated_edge.market_spread',
        'analysis_layer.calculated_edge.market_total',
    ];

    public function __construct(private readonly CompressedJsonPayloadStorage $storage) {}

    public function archive(string $featuresJson, string $metadataJson): array
    {
        $this->assertInlineDocument($featuresJson);
        $this->assertInlineDocument($metadataJson);

        return $this->storage->archive([
            'features_json' => $featuresJson,
            'model_metadata_json' => $metadataJson,
        ]);
    }

    public function projections(array $manifest, string $metadataJson): array
    {
        $metadata = $this->assertInlineDocument($metadataJson);
        $projected = [self::MARKER => $manifest];

        foreach (self::METADATA_SQL_PATHS as $path) {
            if (Arr::has($metadata, $path)) {
                data_set($projected, $path, data_get($metadata, $path));
            }
        }

        return [
            'features' => json_encode([self::MARKER => $manifest], JSON_THROW_ON_ERROR),
            'model_metadata' => json_encode($projected, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ];
    }

    public function manifest(?string $value): ?array
    {
        if ($value === null || ! str_contains($value, '"'.self::MARKER.'"')) {
            return null;
        }

        $document = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document) || ! array_key_exists(self::MARKER, $document)) {
            return null;
        }

        if (! is_array($document[self::MARKER])) {
            throw new RuntimeException('Invalid feature snapshot archive reference.');
        }

        return $document[self::MARKER];
    }

    /** @return array{features_json:string,model_metadata_json:string} */
    public function read(array $manifest): array
    {
        return $this->validatePayload($this->storage->read($manifest));
    }

    /** @param Collection<int, PredictionFeatureSnapshot> $snapshots */
    public function prefetch(Collection $snapshots): void
    {
        $manifests = $snapshots->map(fn (PredictionFeatureSnapshot $snapshot) => $this->manifest($snapshot->getRawOriginal('model_metadata')))
            ->filter();
        $payloads = $this->storage->prefetch($manifests->all());
        foreach ($manifests as $id => $manifest) {
            $payload = $payloads[$manifest['object_key']] ?? null;
            if ($payload !== null) {
                $snapshots->get($id)->cacheArchivePayload($manifest, $this->validatePayload($payload));
            }
        }
    }

    /** @return array{features_json:string,model_metadata_json:string} */
    private function validatePayload(array $payload): array
    {
        if (! is_string($payload['features_json'] ?? null) || ! is_string($payload['model_metadata_json'] ?? null)) {
            throw new RuntimeException('Invalid archived feature snapshot payload.');
        }

        $this->assertInlineDocument($payload['features_json']);
        $this->assertInlineDocument($payload['model_metadata_json']);

        return $payload;
    }

    private function assertInlineDocument(string $json): array
    {
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document) || array_key_exists(self::MARKER, $document)) {
            throw new RuntimeException('Expected inline feature snapshot JSON.');
        }

        return $document;
    }
}
