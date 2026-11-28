<?php

namespace App\Services\CFB\Scoring;

use App\Models\ModelArtifact;
use App\Support\Json\JsonAllocationBudget;
use App\Support\Sports\ExplicitOffsetInstant;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

class CfbScoringArtifact
{
    public const MAXIMUM_BYTES = 16777216;

    public const MAXIMUM_TOKENS = 1000000;

    public const MAXIMUM_DEPTH = 64;

    public function load(string $id, CarbonInterface $asOf, bool $allowResearchShadow = false): array
    {
        $cutoff = LegacyTimestampCarrier::forInstant($asOf);
        if ($cutoff === null) {
            throw new \DomainException('CFB artifact cutoff cannot be represented by its legacy storage clock');
        }
        $model = ModelArtifact::whereKey($id)->where('sport', 'cfb')->where('model_type', 'cfb_possession')->where('created_at', '<=', $cutoff)->firstOrFail();
        $stream = Storage::disk($model->artifact_disk)->readStream($model->artifact_object_key);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Unable to open CFB scoring artifact stream');
        }
        try {
            $json = stream_get_contents($stream, self::MAXIMUM_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($json)) {
            throw new \RuntimeException('Unable to read CFB scoring artifact stream');
        }
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new \RuntimeException('CFB scoring artifact JSON byte budget exceeded before decode.');
        }
        if (! hash_equals($model->artifact_hash, hash('sha256', $json))) {
            throw new \DomainException('CFB artifact integrity failure');
        }
        JsonAllocationBudget::inspect($json, self::MAXIMUM_BYTES, self::MAXIMUM_TOKENS, self::MAXIMUM_DEPTH, 'CFB scoring artifact JSON');
        $artifact = json_decode($json, true, self::MAXIMUM_DEPTH, JSON_THROW_ON_ERROR);
        $sourceAvailableAt = ExplicitOffsetInstant::parse($artifact['max_source_available_at'] ?? null);
        if ($sourceAvailableAt === null) {
            throw new \DomainException('CFB artifact source availability requires an explicit valid instant');
        }
        if ((! $allowResearchShadow && ($artifact['evidence_mode'] ?? '') !== 'recorded_time') || $sourceAvailableAt->gt($asOf)) {
            throw new \DomainException('Retrospective or future evidence cannot feed a live challenger');
        }

        return ['record' => $model, 'payload' => $artifact];
    }
}
