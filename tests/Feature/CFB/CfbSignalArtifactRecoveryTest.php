<?php

use App\Models\CalculationRelease;
use App\Services\CFB\Signals\CfbFootballSignalArtifactStore;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use App\Services\CFB\Signals\CfbFootballSignalHistoricalTrainer;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-26 14:00:00', 'UTC'));
    Storage::fake('local');
    config(['cfb.data.source_disk' => 'local']);
    $this->config = ['football_signals' => ['enabled' => true, 'historical_training' => true, 'catalog' => [], 'maximum_spread_adjustment' => 2, 'maximum_total_adjustment' => 3], 'test_baseline' => 'frozen-production'];
    $this->artifact = ['baseline_hash' => CfbFootballSignalEvidence::baselineHash($this->config),
        'source_game_ids' => [100], 'observations' => [], 'joint_observations' => [], 'limitations' => [],
        'available_at' => CarbonImmutable::now()->toIso8601String(), 'as_of' => CarbonImmutable::now()->toIso8601String()];
});

test('signal artifact survives cache loss and cannot serve a different baseline', function () {
    $store = app(CfbFootballSignalArtifactStore::class);
    $store->save($this->config, $this->artifact);
    Cache::forget(CfbFootballSignalHistoricalTrainer::key($this->config));
    expect($store->load($this->config))->toBe($this->artifact)
        ->and($store->load([...$this->config, 'test_baseline' => 'different']))->toBeNull();
});

test('empty training cannot overwrite working evidence', function () {
    $store = app(CfbFootballSignalArtifactStore::class);
    $store->save($this->config, $this->artifact);
    expect(fn () => $store->save($this->config, [...$this->artifact, 'source_game_ids' => []]))->toThrow(RuntimeException::class);
    Cache::forget(CfbFootballSignalHistoricalTrainer::key($this->config));
    expect($store->load($this->config))->toBe($this->artifact);
});

test('training selects the frozen production release and invalidates its cached evidence', function () {
    $release = CalculationRelease::factory()->create(['sport' => 'cfb', 'phase' => 'pregame', 'status' => 'approved',
        'effective_at' => now()->subDay(), 'retired_at' => null, 'configuration' => $this->config]);
    $key = 'cfb:football-signal-evidence:'.app(CanonicalPayloadHasher::class)->hash($this->config).':'.$release->semantic_version;
    Cache::put($key, ['old' => true]);
    $trainer = Mockery::mock(CfbFootballSignalHistoricalTrainer::class);
    $trainer->shouldReceive('train')->once()->with($this->config, 2022, 2025, Mockery::type(CarbonImmutable::class), Mockery::type('callable'))->andReturn($this->artifact);
    app()->instance(CfbFootballSignalHistoricalTrainer::class, $trainer);
    $this->artisan('cfb:train-football-signals')->assertSuccessful();
    expect(Cache::has($key))->toBeFalse();
});

test('missing evidence recovery reuses and archives a compatible existing artifact', function () {
    CalculationRelease::factory()->create(['sport' => 'cfb', 'phase' => 'pregame', 'status' => 'approved',
        'effective_at' => now()->subDay(), 'retired_at' => null, 'configuration' => $this->config]);
    Cache::put(CfbFootballSignalHistoricalTrainer::key($this->config), $this->artifact);
    $trainer = Mockery::mock(CfbFootballSignalHistoricalTrainer::class);
    $trainer->shouldNotReceive('train');
    app()->instance(CfbFootballSignalHistoricalTrainer::class, $trainer);
    $this->artisan('cfb:train-football-signals', ['--if-missing' => true])->assertSuccessful();
    Storage::assertExists(app(CfbFootballSignalArtifactStore::class)->path($this->config));
});

test('checkpoint belongs to one release and season range and expires after one day', function () {
    $store = app(CfbFootballSignalArtifactStore::class);
    $data = ['as_of' => CarbonImmutable::now()->toIso8601String(), 'source_game_ids' => [1, 2]];
    $store->saveCheckpoint($this->config, 2022, 2025, $data);
    expect($store->checkpoint($this->config, 2022, 2025))->toBe($data)
        ->and($store->checkpoint($this->config, 2023, 2025))->toBeNull();
    $this->travel(25)->hours();
    expect($store->checkpoint($this->config, 2022, 2025))->toBeNull();
});
