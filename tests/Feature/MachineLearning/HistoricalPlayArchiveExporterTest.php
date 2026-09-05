<?php

use App\Models\DatasetExportManifest;
use App\Models\MLB\Game;
use App\Models\MLB\Play;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('archive-test');
    config()->set('ml.storage.disk', 'archive-test');
    config()->set('ml.storage.prefix', 'private-ml');
    config()->set('filesystems.disks.archive-test.driver', 'local');
});

it('exports a content-addressed season partition without changing source rows', function () {
    [$home, $away] = Team::factory()->count(2)->create();
    $includedGame = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);
    $excludedGame = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    $included = Play::query()->create([
        'game_id' => $includedGame->id,
        'espn_play_id' => 'included-1',
        'sequence_number' => 1,
        'play_text' => 'Export this play',
    ]);
    Play::query()->create([
        'game_id' => $includedGame->id,
        'espn_play_id' => 'included-2',
        'sequence_number' => 2,
        'play_text' => 'Export this play too',
    ]);
    $excluded = Play::query()->create([
        'game_id' => $excludedGame->id,
        'espn_play_id' => 'excluded',
        'sequence_number' => 1,
        'play_text' => 'Do not export this season',
    ]);

    $sourceCount = Play::query()->count();

    $this->artisan('ml:export-historical-plays', [
        'sport' => 'mlb',
        'season' => 2025,
        '--chunk' => 1,
    ])
        ->expectsOutputToContain('Historical play partition exported.')
        ->assertSuccessful();

    $manifest = DatasetExportManifest::query()->sole();
    Storage::disk('archive-test')->assertExists([$manifest->object_key, $manifest->manifest_key]);
    expect(Storage::disk('archive-test')->getVisibility($manifest->object_key))->toBe('private')
        ->and(Storage::disk('archive-test')->getVisibility($manifest->manifest_key))->toBe('private');

    $contents = Storage::disk('archive-test')->get($manifest->object_key);
    $records = collect(explode("\n", trim($contents)))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));
    $storedManifestContents = Storage::disk('archive-test')->get($manifest->manifest_key);
    $storedManifest = json_decode($storedManifestContents, true, flags: JSON_THROW_ON_ERROR);

    expect($manifest->dataset)->toBe('historical-plays')
        ->and($manifest->sport)->toBe('mlb')
        ->and($manifest->season)->toBe(2025)
        ->and($manifest->format)->toBe('jsonl')
        ->and($manifest->content_type)->toBe('application/x-ndjson')
        ->and($manifest->row_count)->toBe(2)
        ->and($manifest->source_table)->toBe('mlb_plays')
        ->and($manifest->source_max_id)->toBeGreaterThanOrEqual($included->id)
        ->and($manifest->sha256)->toBe(hash('sha256', $contents))
        ->and($manifest->manifest_sha256)->toBe(hash('sha256', $storedManifestContents))
        ->and($manifest->schema_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($manifest->uri)->toStartWith('storage://archive-test/')
        ->and($records->pluck('espn_play_id')->all())->toBe(['included-1', 'included-2'])
        ->and($records->pluck('id')->all())->not->toContain($excluded->id)
        ->and(data_get($storedManifest, 'partition'))->toBe(['sport' => 'mlb', 'season' => 2025])
        ->and(data_get($storedManifest, 'row_count'))->toBe(2)
        ->and(data_get($storedManifest, 'schema'))->toBeArray()->not->toBeEmpty()
        ->and(data_get($storedManifest, 'source.deletion_performed'))->toBeFalse()
        ->and(Play::query()->count())->toBe($sourceCount);

    $this->artisan('ml:export-historical-plays mlb 2025 --chunk=1')->assertSuccessful();

    expect(DatasetExportManifest::query()->count())->toBe(1)
        ->and(Play::query()->count())->toBe($sourceCount);
});

it('fails safely when a partition has no source rows', function () {
    $this->artisan('ml:export-historical-plays mlb 2025')
        ->expectsOutputToContain('No mlb play rows exist for season 2025.')
        ->assertExitCode(1);

    expect(DatasetExportManifest::query()->count())->toBe(0)
        ->and(Storage::disk('archive-test')->allFiles())->toBe([]);
});

it('does not disguise the portable intermediate as parquet', function () {
    config()->set('ml.archive.python_binary', '/definitely/missing/python');

    $this->artisan('ml:export-historical-plays mlb 2025 --format=parquet')
        ->expectsOutputToContain('Parquet export requires a configured Python runtime with PyArrow')
        ->assertExitCode(1);

    expect(DatasetExportManifest::query()->count())->toBe(0)
        ->and(Storage::disk('archive-test')->allFiles())->toBe([]);
});

it('refuses to overwrite a corrupt immutable object', function () {
    [$home, $away] = Team::factory()->count(2)->create();
    $game = Game::factory()->create([
        'season' => 2025,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);
    Play::query()->create([
        'game_id' => $game->id,
        'espn_play_id' => 'immutable-1',
        'sequence_number' => 1,
    ]);

    $this->artisan('ml:export-historical-plays mlb 2025')->assertSuccessful();
    $manifest = DatasetExportManifest::query()->sole();
    Storage::disk('archive-test')->put($manifest->object_key, 'corrupt');

    $this->artisan('ml:export-historical-plays mlb 2025')
        ->expectsOutputToContain('Refusing to overwrite immutable dataset object')
        ->assertExitCode(1);

    expect(Storage::disk('archive-test')->get($manifest->object_key))->toBe('corrupt')
        ->and(Play::query()->count())->toBe(1)
        ->and(DatasetExportManifest::query()->count())->toBe(1);
});
