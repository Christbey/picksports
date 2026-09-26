<?php

namespace App\Services\CFB\Scoring;

use App\Models\ModelArtifact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

class CfbScoringArtifact
{
    public function load(string $id, CarbonInterface $asOf, bool $allowResearchShadow = false): array
    {
        $model = ModelArtifact::whereKey($id)->where('sport', 'cfb')->where('model_type', 'cfb_possession')->where('created_at', '<=', $asOf)->firstOrFail();
        $json = Storage::disk($model->artifact_disk)->get($model->artifact_object_key);
        if (! hash_equals($model->artifact_hash, hash('sha256', $json))) {
            throw new \DomainException('CFB artifact integrity failure');
        }
        $artifact = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ((! $allowResearchShadow && ($artifact['evidence_mode'] ?? '') !== 'recorded_time') || CarbonImmutable::parse($artifact['max_source_available_at'])->gt($asOf)) {
            throw new \DomainException('Retrospective or future evidence cannot feed a live challenger');
        }

        return ['record' => $model, 'payload' => $artifact];
    }
}
