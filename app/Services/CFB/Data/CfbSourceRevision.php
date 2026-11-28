<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\ProviderImportManifest;
use App\Models\ProviderSourceFile;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Facades\Storage;

class CfbSourceRevision
{
    public function record(Game $game, string $component, array $payload, array $validation,
        string $provider = 'espn', string $validatorVersion = CfbGameDataValidator::VERSION): GameDataCheck
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $hash = hash('sha256', $json);
        $path = "cfb/sources/{$game->id}/{$component}/{$hash}.json";
        $disk = config('cfb.data.source_disk', 'local');
        if (! Storage::disk($disk)->exists($path) && ! Storage::disk($disk)->put($path, $json)) {
            throw new \RuntimeException('CFB source archive failed');
        }

        return $game->getConnection()->transaction(function () use ($game, $component, $validation, $provider, $validatorVersion, $hash, $disk, $path, $json): GameDataCheck {
            $locked = Game::on($game->getConnectionName())->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $recorder = app(CfbSourceAssessmentRecorder::class);
            $context = $recorder->context($locked);
            if ($recorder->context($game) !== $context) {
                $validation = ['state' => 'partial', 'discrepancies' => ['game_context_changed_before_assessment'],
                    'evidence' => ['supplied_assessment' => $validation, 'supplied_context' => $recorder->context($game)]];
            }
            $file = ProviderSourceFile::on($game->getConnectionName())->lockForUpdate()->firstOrCreate(['provider' => $provider, 'dataset' => 'cfb_'.$component, 'sha256' => $hash],
                ['disk' => $disk, 'object_key' => $path, 'uri' => $disk.':'.$path, 'original_filename' => $component.'.json', 'content_type' => 'application/json', 'size_bytes' => strlen($json), 'metadata' => ['game_id' => $game->id, 'retrieved_at' => now()->toIso8601String()]]);
            $manifest = ProviderImportManifest::on($game->getConnectionName())->lockForUpdate()->firstOrCreate(['provider_source_file_id' => $file->id, 'provider' => $provider, 'dataset' => 'cfb_'.$component],
                ['status' => $validation['state'] === 'complete' ? 'validated' : 'quarantined', 'started_at' => now(), 'completed_at' => now(), 'options' => $validation]);
            $check = GameDataCheck::on($game->getConnectionName())->lockForUpdate()->firstOrCreate(['game_id' => $game->id, 'component' => $component, 'source_hash' => $hash, 'validator_version' => $validatorVersion],
                [...$validation, 'source_path' => $path]);
            $new = $check->wasRecentlyCreated;
            $check = GameDataCheck::on($game->getConnectionName())->whereKey($check->id)->lockForUpdate()->firstOrFail();
            $latest = $recorder->latest($check);
            $hasher = app(CanonicalPayloadHasher::class);
            $currentId = $recorder->currentCheck($locked, $component, [$validatorVersion])?->id;
            if ($currentId === $check->id && $latest !== null && $latest->context_hash === $hasher->hash($context)
                && $latest->assessment_hash === $recorder->assessmentHash($validation, $context, $recorder->manifestIdentity($manifest->toArray()))
                && $recorder->projectionMatches($check, $validation)) {
                return $check;
            }
            if (! $new) {
                $recorder->baseline($check);
                $snapshot = $check->attributesToArray();
                unset($snapshot['created_at'], $snapshot['updated_at']);
                if ($latest !== null && $hasher->hash($snapshot) !== $hasher->hash($latest->projection_snapshot)) {
                    $recorder->append($check, $context, 'projection_drift');
                }
            }
            $reuseAcceptance = $latest !== null && $latest->context_hash === $hasher->hash($context)
                && $latest->assessment_hash === $recorder->assessmentHash($validation, $context, $recorder->manifestIdentity($manifest->toArray()))
                && $recorder->projectionMatches($check, $validation) && $check->isCurrentAccepted();
            // This is the mutable current projection, not the historical observation.
            // Bypass its acceptance hook only while the source recorder owns game/check locks.
            $check->getConnection()->table($check->getTable())->where('id', $check->id)->update([
                'state' => $validation['state'], 'discrepancies' => json_encode($validation['discrepancies'], JSON_THROW_ON_ERROR),
                'evidence' => json_encode($validation['evidence'], JSON_THROW_ON_ERROR), 'accepted_at' => $reuseAcceptance ? $check->accepted_at : null, 'updated_at' => now(),
            ]);
            $manifest->update(['status' => $validation['state'] === 'complete' ? 'validated' : 'quarantined',
                'options' => $validation, 'completed_at' => now()]);
            $check->refresh();
            $recorder->append($check, $context, 'observed_assessment', $manifest->fresh()->toArray());

            return $check;
        }, 3);
    }
}
