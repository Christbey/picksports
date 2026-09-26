<?php

namespace App\Services\CFB\Signals;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CfbFootballSignalArtifactStore
{
    public function path(array $configuration): string
    {
        return 'cfb/signals/history/'.hash('sha256', CfbFootballSignalHistoricalTrainer::key($configuration)).'.json';
    }

    public function checkpointPath(array $configuration, int $from, int $to): string
    {
        return str_replace('.json', "-progress-{$from}-{$to}.json", $this->path($configuration));
    }

    public function checkpoint(array $configuration, int $from, int $to): ?array
    {
        $disk = Storage::disk(config('cfb.data.source_disk', 'local'));
        $path = $this->checkpointPath($configuration, $from, $to);
        if (! $disk->exists($path)) {
            return null;
        }
        $data = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        $asOf = CarbonImmutable::parse($data['as_of']);

        return $asOf->lte(now()) && $asOf->gte(now()->subDay()) ? $data : null;
    }

    public function saveCheckpoint(array $configuration, int $from, int $to, array $data): void
    {
        if (! Storage::disk(config('cfb.data.source_disk', 'local'))->put($this->checkpointPath($configuration, $from, $to), json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))) {
            throw new RuntimeException('Could not save CFB signal training progress.');
        }
    }

    public function clearCheckpoint(array $configuration, int $from, int $to): void
    {
        Storage::disk(config('cfb.data.source_disk', 'local'))->delete($this->checkpointPath($configuration, $from, $to));
    }

    public function load(array $configuration): ?array
    {
        $key = CfbFootballSignalHistoricalTrainer::key($configuration);
        $artifact = Cache::get($key);
        if (is_array($artifact)) {
            return $artifact;
        }
        $disk = Storage::disk(config('cfb.data.source_disk', 'local'));
        $path = $this->path($configuration);
        if (! $disk->exists($path)) {
            return null;
        }
        $artifact = json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($artifact) || ($artifact['baseline_hash'] ?? null) !== CfbFootballSignalEvidence::baselineHash($configuration)) {
            throw new RuntimeException('Stored CFB signal history is incompatible with the selected release.');
        }
        Cache::put($key, $artifact, now()->addDays(8));

        return $artifact;
    }

    public function save(array $configuration, array $artifact): void
    {
        if (empty($artifact['source_game_ids'])) {
            throw new RuntimeException('Signal training produced no eligible games; previous artifact preserved.');
        }
        $json = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (! Storage::disk(config('cfb.data.source_disk', 'local'))->put($this->path($configuration), $json)) {
            throw new RuntimeException('Could not persist CFB signal training evidence.');
        }
        Cache::put(CfbFootballSignalHistoricalTrainer::key($configuration), $artifact, now()->addDays(8));
    }
}
