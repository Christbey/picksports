<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\CFB\Scoring\CfbScoringArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('requires an explicit real source instant before CFB artifact admission', function (mixed $sourceClock, bool $accepted = false) {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T18:00:00Z'));
    Storage::fake('local');
    $payload = json_decode(file_get_contents(__DIR__.'/../../Fixtures/cfb-scoring-synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
    $payload['max_source_available_at'] = $sourceClock;
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put('clock-boundary.json', $json);
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'cfb', 'run_type' => 'training', 'blend_version' => 'cfb-possession-v1', 'model_version' => 'test', 'feature_version' => 'test', 'config_hash' => hash('sha256', 'test'), 'code_version' => 'test', 'status' => 'completed', 'started_at' => now()]);
    $artifact = ModelArtifact::create(['training_run_id' => $run->id, 'sport' => 'cfb', 'market_type' => 'multi_market', 'model_type' => 'cfb_possession', 'model_version' => 'test', 'feature_version' => 'test', 'status' => 'challenger', 'artifact_disk' => 'local', 'artifact_object_key' => 'clock-boundary.json', 'artifact_path' => 'clock-boundary.json', 'dataset_hash' => hash('sha256', 'fixture'), 'artifact_hash' => hash('sha256', $json)]);

    if ($accepted) {
        expect(app(CfbScoringArtifact::class)->load($artifact->id, now())['payload']['max_source_available_at'])->toBe($sourceClock)
            ->and(app(CfbScoringArtifact::class)->load($artifact->id, now(), true)['payload']['max_source_available_at'])->toBe($sourceClock);

        return;
    }

    expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))
        ->toThrow(DomainException::class, 'source availability');
    expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now(), true))
        ->toThrow(DomainException::class, 'source availability');
})->with([
    'explicit UTC' => ['2026-11-27T17:59:59Z', true],
    'offset compared by instant' => ['2026-11-28T01:00:00.123456+08:00', true],
    'missing' => [null],
    'unqualified' => ['2026-11-27 10:00:00'],
    'relative' => ['yesterday'],
    'calendar rollover' => ['2026-02-30T00:00:00Z'],
]);

it('compares artifact creation against the requested instant using its legacy storage carrier', function (string $createdAt, bool $allowed) {
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-11-27T18:00:00Z'));
    Storage::fake('local');
    $json = file_get_contents(__DIR__.'/../../Fixtures/cfb-scoring-synthetic.json');
    Storage::disk('local')->put('clock-boundary.json', $json);
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'cfb', 'run_type' => 'training', 'blend_version' => 'cfb-possession-v1', 'model_version' => 'test', 'feature_version' => 'test', 'config_hash' => hash('sha256', 'test'), 'code_version' => 'test', 'status' => 'completed', 'started_at' => now()]);
    $artifact = new ModelArtifact(['training_run_id' => $run->id, 'sport' => 'cfb', 'market_type' => 'multi_market', 'model_type' => 'cfb_possession', 'model_version' => 'test', 'feature_version' => 'test', 'status' => 'challenger', 'artifact_disk' => 'local', 'artifact_object_key' => 'clock-boundary.json', 'artifact_path' => 'clock-boundary.json', 'dataset_hash' => hash('sha256', 'fixture'), 'artifact_hash' => hash('sha256', $json)]);
    $artifact->created_at = $createdAt;
    $artifact->save();
    expect($artifact->fresh()->getRawOriginal('created_at'))->toBe($createdAt);
    $cutoff = CarbonImmutable::parse('2026-11-27T12:00:00Z');
    if ($allowed) {
        expect(app(CfbScoringArtifact::class)->load($artifact->id, $cutoff)['payload']['schema'])->toBe('cfb-possession-v1');
    } else {
        expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, $cutoff))->toThrow(ModelNotFoundException::class);
    }
})->with([
    'created after 06:00 Chicago cutoff' => ['2026-11-27 10:00:00', false],
    'created before 06:00 Chicago cutoff' => ['2026-11-27 05:00:00', true],
]);
