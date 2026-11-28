<?php

use App\Console\Commands\CFB\ExportScoringTrainingCommand;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\DatasetExportManifest;
use App\Models\SportEvent;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/CfbAssessmentFixtures.php';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('cfb_export_publication');
    config(['cfb.scoring.artifact_disk' => 'cfb_export_publication']);
    $this->freezeTime();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->subDay()]);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 7, 'away_score' => 0]);
    $ids = [];
    $hash = hash('sha256', 'publication-synthetic-source');
    foreach (['boxscore', 'plays'] as $component) {
        $check = cfbObservedAcceptedFixture(['game_id' => $game->id, 'component' => $component,
            'source_hash' => $hash, 'source_path' => 'synthetic.json', 'validator_version' => CfbGameDataValidator::VERSION,
            'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'accepted_at' => now()]);
        $ids[$component] = app(CfbSourceAssessmentRecorder::class)->acceptanceReceipt($check)->id;
    }
    DB::table('cfb_drives')->insert(['game_id' => $game->id, 'source_revision' => $hash, 'drive_key' => '1',
        'offense_team_id' => $game->home_team_id, 'quality' => 'complete', 'created_at' => now(), 'updated_at' => now(),
        'data' => json_encode(['normalizer_version' => CfbDriveBuilder::VERSION, 'score_ledger_revision' => $hash,
            'play_assessment_receipt_id' => $ids['plays'], 'boxscore_assessment_receipt_id' => $ids['boxscore'],
            'offense_points' => 7, 'opponent_points' => 0, 'period' => 1])]);
    $this->exportPath = sys_get_temp_dir().'/cfb-publication-'.Str::uuid().'.jsonl';
    $this->exportOptions = ['--from-season' => 2026, '--to-season' => 2026,
        '--as-of' => now()->addMinute()->toIso8601String(), '--output' => $this->exportPath];
});

afterEach(function () {
    foreach ([$this->exportPath, $this->exportPath.'.manifest.json', $this->exportPath.'.second', $this->exportPath.'.second.manifest.json'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('refuses registry publication when an artifact write returns false', function (string $failure) {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $writes = [];
    $disk->shouldReceive('put')->andReturnUsing(function (string $key, mixed $content) use (&$writes, $failure): bool {
        $kind = is_resource($content) ? 'data' : 'manifest';
        $writes[] = $kind;

        return $failure !== $kind;
    });
    $disk->shouldReceive('exists')->andReturnFalse();
    $disk->shouldNotReceive('delete');
    Storage::set('cfb_export_publication', $disk);

    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertFailed();

    expect(DatasetExportManifest::query()->count())->toBe(0)
        ->and($writes)->toBe($failure === 'data' ? ['data'] : ['data', 'manifest'])
        ->and(is_file($this->exportPath))->toBeTrue();
})->with(['data', 'manifest']);

it('closes the export stream when its upload throws without publishing registry metadata', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $captured = null;
    $disk->shouldReceive('put')->once()->andReturnUsing(function (string $key, mixed $content) use (&$captured): never {
        $captured = $content;
        throw new RuntimeException('synthetic upload failure');
    });
    $disk->shouldReceive('exists')->andReturnFalse();
    $disk->shouldNotReceive('delete');
    Storage::set('cfb_export_publication', $disk);
    try {
        $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertFailed();
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('synthetic upload failure');
    }

    expect($captured)->not->toBeNull()
        ->and(is_resource($captured))->toBeFalse()
        ->and(DatasetExportManifest::query()->count())->toBe(0);
});

it('registers a complete export only after exact data and manifest bytes are stored', function () {
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertSuccessful();
    $record = DatasetExportManifest::query()->sole();
    $disk = Storage::disk('cfb_export_publication');
    expect($record->row_count)->toBe(1)
        ->and($disk->get($record->object_key))->toBe(file_get_contents($this->exportPath))
        ->and($disk->get($record->manifest_key))->toBe(file_get_contents($this->exportPath.'.manifest.json'))
        ->and(hash('sha256', $disk->get($record->object_key)))->toBe($record->sha256)
        ->and(hash('sha256', $disk->get($record->manifest_key)))->toBe($record->manifest_sha256);
});

it('refuses different manifest bytes at a shared data key without overwriting stored objects or registry', function () {
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertSuccessful();
    $record = DatasetExportManifest::query()->sole();
    $disk = Storage::disk('cfb_export_publication');
    $data = $disk->get($record->object_key);
    $manifest = $disk->get($record->manifest_key);
    $this->artisan('cfb:export-scoring-training', [...$this->exportOptions, '--output' => $this->exportPath.'.second'])->assertFailed();

    expect($disk->get($record->object_key))->toBe($data)
        ->and($disk->get($record->manifest_key))->toBe($manifest)
        ->and($record->fresh()->manifest_sha256)->toBe(hash('sha256', $manifest))
        ->and(DatasetExportManifest::query()->count())->toBe(1);
});

it('allows identical retry without changing the existing stored objects or immutable registry', function () {
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertSuccessful();
    $record = DatasetExportManifest::query()->sole();
    $before = $record->getAttributes();
    $disk = Storage::disk('cfb_export_publication');
    $data = $disk->get($record->object_key);
    $manifest = $disk->get($record->manifest_key);
    unlink($this->exportPath);
    unlink($this->exportPath.'.manifest.json');
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertSuccessful();

    expect($record->fresh()->getAttributes())->toBe($before)
        ->and($disk->get($record->object_key))->toBe($data)
        ->and($disk->get($record->manifest_key))->toBe($manifest)
        ->and(DatasetExportManifest::query()->count())->toBe(1);
});

it('retains an empty scan report without publishing a remote dataset or registry', function () {
    Game::query()->update(['status' => 'STATUS_SCHEDULED']);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldNotReceive('put');
    $disk->shouldNotReceive('delete');
    Storage::set('cfb_export_publication', $disk);
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertExitCode(2);

    expect(DatasetExportManifest::query()->count())->toBe(0)
        ->and(json_decode(file_get_contents($this->exportPath.'.manifest.json'), true)['coverage']['included'])->toBe(0);
});

it('content addresses each manifest independently from its data object', function () {
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertSuccessful();
    $record = DatasetExportManifest::query()->sole();
    expect($record->manifest_key)->toBe($record->object_key.'.'.$record->manifest_sha256.'.manifest.json');
});

class CfbPublicationCountingStream
{
    public $context;

    public static string $body = '';

    public static int $readBytes = 0;

    public static bool $closed = false;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $part = substr(self::$body, $this->position, $count);
        $this->position += strlen($part);
        self::$readBytes += strlen($part);

        return $part;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}

it('bounds existing object reads to expected local size plus one and closes the oversized stream', function () {
    if (! in_array('cfbpublicationcounter', stream_get_wrappers(), true)) {
        stream_wrapper_register('cfbpublicationcounter', CfbPublicationCountingStream::class);
    }
    $disk = Mockery::mock(FilesystemAdapter::class);
    $expected = 0;
    $disk->shouldReceive('exists')->andReturnUsing(fn (string $key): bool => ! str_ends_with($key, '.manifest.json'));
    $disk->shouldReceive('readStream')->once()->andReturnUsing(function () use (&$expected) {
        $expected = filesize($this->exportPath);
        CfbPublicationCountingStream::$body = file_get_contents($this->exportPath).str_repeat('x', 10000);
        CfbPublicationCountingStream::$readBytes = 0;
        CfbPublicationCountingStream::$closed = false;

        return fopen('cfbpublicationcounter://synthetic', 'rb');
    });
    $disk->shouldNotReceive('put');
    $disk->shouldNotReceive('delete');
    Storage::set('cfb_export_publication', $disk);
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertFailed();

    expect(CfbPublicationCountingStream::$readBytes)->toBeLessThanOrEqual($expected + 1)
        ->and(CfbPublicationCountingStream::$closed)->toBeTrue()
        ->and(DatasetExportManifest::query()->count())->toBe(0);
});

it('preserves an exact matching legacy manifest key on retry', function () {
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertSuccessful();
    $record = DatasetExportManifest::query()->sole();
    $legacyKey = $record->object_key.'.manifest.json';
    $disk = Storage::disk('cfb_export_publication');
    $manifest = $disk->get($record->manifest_key);
    $disk->put($legacyKey, $manifest);
    DB::table('dataset_export_manifests')->where('id', $record->id)->update(['manifest_key' => $legacyKey]);
    unlink($this->exportPath);
    unlink($this->exportPath.'.manifest.json');
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertSuccessful();

    expect($record->fresh()->manifest_key)->toBe($legacyKey)
        ->and($disk->get($legacyKey))->toBe($manifest)
        ->and(DatasetExportManifest::query()->count())->toBe(1);
});

it('refuses a different persisted registry winner after its preflight without deleting either manifest', function () {
    $command = new class extends ExportScoringTrainingCommand
    {
        public Closure $registration;

        protected function registerExport(array $identity, array $values): DatasetExportManifest
        {
            return ($this->registration)($identity, $values);
        }
    };
    $winner = null;
    $command->registration = function (array $identity, array $values) use (&$winner) {
        $manifest = $values['metadata'];
        $manifest['data_path'] = 'synthetic-competing-export';
        $bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $bytes);
        $key = $values['object_key'].'.'.$hash.'.manifest.json';
        Storage::disk($values['disk'])->put($key, $bytes);

        return $winner = DatasetExportManifest::create([...$identity, ...$values, 'manifest_key' => $key,
            'manifest_sha256' => $hash, 'metadata' => $manifest]);
    };
    $this->app->instance(ExportScoringTrainingCommand::class, $command);
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertFailed();

    expect($winner)->not->toBeNull()
        ->and(DatasetExportManifest::query()->count())->toBe(1)
        ->and(hash('sha256', Storage::disk($winner->disk)->get($winner->manifest_key)))->toBe($winner->manifest_sha256)
        ->and(Storage::disk($winner->disk)->allFiles())->toHaveCount(3);
});

it('fails closed on a missing or malformed existing object stream', function (mixed $stream) {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturnUsing(fn (string $key): bool => ! str_ends_with($key, '.manifest.json'));
    $disk->shouldReceive('readStream')->once()->andReturn($stream);
    $disk->shouldNotReceive('put');
    $disk->shouldNotReceive('delete');
    Storage::set('cfb_export_publication', $disk);
    $this->artisan('cfb:export-scoring-training', $this->exportOptions)->assertFailed();

    expect(DatasetExportManifest::query()->count())->toBe(0);
})->with([false, 'not-a-resource']);
