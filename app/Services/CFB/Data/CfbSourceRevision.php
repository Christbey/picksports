<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\ProviderImportManifest;
use App\Models\ProviderSourceFile;
use Illuminate\Support\Facades\Storage;

class CfbSourceRevision
{
    public function record(Game $game, string $component, array $payload, array $validation): GameDataCheck
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $hash = hash('sha256', $json);
        $path = "cfb/sources/{$game->id}/{$component}/{$hash}.json";
        $disk = config('cfb.data.source_disk', 'local');
        if (! Storage::disk($disk)->exists($path) && ! Storage::disk($disk)->put($path, $json)) {
            throw new \RuntimeException('CFB source archive failed');
        }
        $file = ProviderSourceFile::firstOrCreate(['provider' => 'espn', 'dataset' => 'cfb_'.$component, 'sha256' => $hash],
            ['disk' => $disk, 'object_key' => $path, 'uri' => $disk.':'.$path, 'original_filename' => $component.'.json', 'content_type' => 'application/json', 'size_bytes' => strlen($json), 'metadata' => ['game_id' => $game->id, 'retrieved_at' => now()->toIso8601String()]]);
        ProviderImportManifest::firstOrCreate(['provider_source_file_id' => $file->id, 'provider' => 'espn', 'dataset' => 'cfb_'.$component],
            ['status' => $validation['state'] === 'complete' ? 'validated' : 'quarantined', 'started_at' => now(), 'completed_at' => now(), 'options' => $validation]);

        $check = GameDataCheck::firstOrCreate(['game_id' => $game->id, 'component' => $component, 'source_hash' => $hash, 'validator_version' => CfbGameDataValidator::VERSION],
            [...$validation, 'source_path' => $path]);
        $check->touch();

        return $check;
    }
}
