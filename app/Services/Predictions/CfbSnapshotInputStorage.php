<?php

namespace App\Services\Predictions;

use App\Models\SnapshotMetadataPayload;
use RuntimeException;

class CfbSnapshotInputStorage
{
    public const WRITES_SETTING = 'cfb.snapshot_input_sharing';

    private const SECTIONS = ['historical_signals', 'scoring_challenger', 'football_signal_evidence'];

    public function __construct(private SnapshotMetadataStorage $storage) {}

    public function prepare(array $inputs): array
    {
        foreach (self::SECTIONS as $section) {
            if (! is_array($inputs[$section] ?? null)) {
                continue;
            }

            // Match the original JSON column's encoding, including numeric types.
            $json = json_encode($inputs[$section], JSON_THROW_ON_ERROR);
            $bytes = strlen($json);
            if ($bytes >= 2048 && $bytes <= (int) config('snapshot-storage.maximum_json_bytes', 8 * 1024 * 1024)) {
                $inputs[$section] = $this->storage->storeJson($json);
            }
        }

        return $inputs;
    }

    public function resolve(?array $inputs): ?array
    {
        if ($inputs === null) {
            return null;
        }

        $references = array_filter(array_intersect_key($inputs, array_flip(self::SECTIONS)), $this->storage->isReference(...));
        if ($references === []) {
            return $inputs;
        }

        $payloads = SnapshotMetadataPayload::query()
            ->whereKey(array_values(array_unique(array_map($this->referenceHash(...), $references))))
            ->get()->keyBy('sha256');
        foreach ($references as $section => $reference) {
            $payload = $payloads->get($this->referenceHash($reference));
            if ($payload === null) {
                throw new RuntimeException('Referenced CFB snapshot inputs are missing.');
            }
            $inputs[$section] = json_decode($this->storage->originalJson($reference, $payload), true, 512, JSON_THROW_ON_ERROR);
        }

        return $inputs;
    }

    public function resolveProjectedSection(mixed $value): mixed
    {
        if ($this->storage->isReference($value)) {
            return json_decode($this->storage->originalJson($value), true, 512, JSON_THROW_ON_ERROR);
        }

        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
    }

    private function referenceHash(string $reference): string
    {
        return substr($reference, strlen(SnapshotMetadataStorage::REFERENCE_PREFIX));
    }
}
