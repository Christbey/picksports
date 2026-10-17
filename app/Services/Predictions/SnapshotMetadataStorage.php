<?php

namespace App\Services\Predictions;

use App\Models\SnapshotMetadataPayload;
use RuntimeException;

class SnapshotMetadataStorage
{
    public const REFERENCE_PREFIX = 'picksports:snapshot-metadata:gzip-v1:';

    /**
     * @param  array<array-key, mixed>|null  $metadata
     * @return array<array-key, mixed>|string|null
     */
    public function prepare(?array $metadata, string $sport): array|string|null
    {
        if ($metadata === null || ! config('snapshot-storage.share_metadata')
            || ! in_array($sport, config('snapshot-storage.sports', []), true)) {
            return $metadata;
        }

        $bytes = strlen(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        if ($bytes < (int) config('snapshot-storage.minimum_json_bytes') || $bytes > $this->limit()) {
            return $metadata;
        }

        return $this->store($metadata);
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     */
    public function store(array $metadata): string
    {
        return $this->storeJson($this->encode($metadata));
    }

    public function storeJson(string $json): string
    {
        if (strlen($json) > $this->limit()) {
            throw new RuntimeException('Snapshot metadata exceeds the size limit.');
        }
        $this->jsonSignature($json);
        $compressed = gzencode($json, 6);
        if ($compressed === false) {
            throw new RuntimeException('Snapshot metadata compression failed.');
        }

        $hash = hash('sha256', $json);
        $payload = SnapshotMetadataPayload::query()->firstOrCreate(['sha256' => $hash], [
            'compressed_sha256' => hash('sha256', $compressed),
            'json_bytes' => strlen($json),
            'compressed_bytes' => strlen($compressed),
            'payload' => base64_encode($compressed),
        ]);
        if ($this->decodeJson($payload) !== $json) {
            throw new RuntimeException('Snapshot metadata failed exact read-back verification.');
        }

        return self::REFERENCE_PREFIX.$hash;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function resolve(?string $raw, ?SnapshotMetadataPayload $loaded = null): ?array
    {
        if ($raw === null) {
            return null;
        }

        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (! is_string($value) || ! $this->isReference($value)) {
            throw new RuntimeException('Unsupported snapshot metadata value.');
        }

        return json_decode($this->originalJson($value, $loaded), true, 512, JSON_THROW_ON_ERROR);
    }

    public function originalJson(string $reference, ?SnapshotMetadataPayload $loaded = null): string
    {
        if (! $this->isReference($reference)) {
            throw new RuntimeException('Unsupported snapshot metadata reference.');
        }
        $hash = substr($reference, strlen(self::REFERENCE_PREFIX));
        $payload = $loaded !== null && hash_equals($hash, $loaded->sha256)
            ? $loaded : SnapshotMetadataPayload::query()->find($hash);
        if ($payload === null) {
            throw new RuntimeException('Referenced snapshot metadata is missing.');
        }

        return $this->decodeJson($payload);
    }

    public function jsonSignature(string $json): string
    {
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value) && ! is_object($value)) {
            throw new RuntimeException('Snapshot metadata must contain an array or object.');
        }

        return hash('sha256', json_encode($this->canonicalValue($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $fields = get_object_vars($value);
            ksort($fields);

            return (object) array_map($this->canonicalValue(...), $fields);
        }

        return is_array($value) ? array_map($this->canonicalValue(...), $value) : $value;
    }

    public function isReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A'.preg_quote(self::REFERENCE_PREFIX, '/').'[a-f0-9]{64}\z/', $value) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     */
    public function encode(array $metadata): string
    {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > $this->limit()) {
            throw new RuntimeException('Snapshot metadata exceeds the size limit.');
        }

        return $json;
    }

    private function decodeJson(SnapshotMetadataPayload $payload): string
    {
        if ($payload->json_bytes < 1 || $payload->json_bytes > $this->limit()
            || $payload->compressed_bytes < 1 || $payload->compressed_bytes > $this->limit() + 65536
            || strlen($payload->payload) > (int) ceil(($this->limit() + 65536) / 3) * 4) {
            throw new RuntimeException('Snapshot metadata size is invalid.');
        }

        $compressed = base64_decode($payload->payload, true);
        if ($compressed === false || strlen($compressed) !== $payload->compressed_bytes
            || ! hash_equals($payload->compressed_sha256, hash('sha256', $compressed))) {
            throw new RuntimeException('Snapshot metadata compressed checksum mismatch.');
        }

        $json = @gzdecode($compressed, $this->limit() + 1);
        if ($json === false || strlen($json) !== $payload->json_bytes
            || ! hash_equals($payload->sha256, hash('sha256', $json))) {
            throw new RuntimeException('Snapshot metadata JSON checksum mismatch.');
        }

        $this->jsonSignature($json);

        return $json;
    }

    private function limit(): int
    {
        return max(1, (int) config('snapshot-storage.maximum_json_bytes', 8 * 1024 * 1024));
    }
}
