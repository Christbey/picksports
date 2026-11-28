<?php

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/picksports-cfb-training-boundary-'.Str::uuid();
    File::ensureDirectoryExists($this->directory, 0700);
    Storage::fake('local');
    config(['cfb.scoring.artifact_disk' => 'local']);
    $this->manifestPath = $this->directory.'/manifest.json';
    $this->outputPath = $this->directory.'/artifact.json';
    $this->manifest = ['sha256' => hash('sha256', 'synthetic'), 'data_path' => $this->directory.'/data.csv',
        'scan' => ['complete' => true, 'has_more' => false, 'after_game_id' => 0]];
    File::put($this->manifestPath, json_encode($this->manifest, JSON_THROW_ON_ERROR));
    $payload = json_decode(File::get(__DIR__.'/../../Fixtures/cfb-scoring-synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
    $payload['dataset_hash'] = $this->manifest['sha256'];
    $payload['holdout'] = ['games' => [], 'mean_energy' => null];
    $payload['promotion_report'] = ['allowed' => false, 'reason' => 'synthetic_process_boundary_only'];
    File::put($this->outputPath, json_encode($payload, JSON_THROW_ON_ERROR));
    Process::preventStrayProcesses();
    Process::fake();
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('removes unrelated parent credentials from the CFB trainer environment', function () {
    $name = 'PICKSPORTS_CFB_TRAINING_BOUNDARY_SENTINEL';
    $old = getenv($name);
    putenv($name.'=synthetic-only');
    try {
        $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $this->manifestPath, '--output' => $this->outputPath])->assertSuccessful();
        Process::assertRan(fn (PendingProcess $process): bool => ($process->environment[$name] ?? null) === false
            && ($process->environment['PYTHONNOUSERSITE'] ?? null) === '1');
    } finally {
        putenv($old === false ? $name : $name.'='.$old);
    }
    expect(ModelArtifact::count())->toBe(1)->and(ModelRun::sole()->status)->toBe('completed');
});

it('refuses excessive trainer manifests before recording a run or starting a child', function (string $kind) {
    $raw = json_encode($this->manifest, JSON_THROW_ON_ERROR);
    $prefix = substr($raw, 0, -1).',"padding":';
    $padding = match ($kind) {
        'bytes' => '"'.str_repeat('x', 2097152).'"',
        'tokens' => '['.str_repeat('0,', 51000).'0]',
        default => str_repeat('[', 40).'0'.str_repeat(']', 40),
    };
    File::put($this->manifestPath, $prefix.$padding.'}');

    $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $this->manifestPath, '--output' => $this->outputPath])->assertFailed();
    Process::assertNothingRan();
    expect(ModelRun::count())->toBe(0)->and(ModelArtifact::count())->toBe(0);
})->with(['bytes', 'tokens', 'depth']);

it('refuses an oversized trainer artifact before archive or registration', function () {
    $json = json_decode(File::get($this->outputPath), true, 512, JSON_THROW_ON_ERROR);
    $prefix = substr(json_encode($json, JSON_THROW_ON_ERROR), 0, -1).',"padding":"';
    $file = fopen($this->outputPath, 'wb');
    fwrite($file, $prefix);
    for ($i = 0; $i < 257; $i++) {
        fwrite($file, str_repeat('x', 65536));
    }
    fwrite($file, '"}');
    fclose($file);
    $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $this->manifestPath, '--output' => $this->outputPath])->assertFailed();
    expect(ModelArtifact::count())->toBe(0)->and(ModelRun::sole()->status)->toBe('failed');
    Storage::disk('local')->assertDirectoryEmpty('cfb/models');
});

it('refuses a trainer artifact from a different admitted dataset', function () {
    $payload = json_decode(File::get($this->outputPath), true, 64, JSON_THROW_ON_ERROR);
    $payload['dataset_hash'] = hash('sha256', 'other-synthetic-dataset');
    File::put($this->outputPath, json_encode($payload, JSON_THROW_ON_ERROR));
    $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $this->manifestPath, '--output' => $this->outputPath])->assertFailed();
    expect(ModelArtifact::count())->toBe(0)->and(ModelRun::sole()->status)->toBe('failed');
    Storage::disk('local')->assertDirectoryEmpty('cfb/models');
});

it('pins the exact admitted manifest while the original path changes', function () {
    $original = File::get($this->manifestPath);
    $stagedPath = null;
    Process::fake(['*' => function (PendingProcess $process) use ($original, &$stagedPath) {
        $stagedPath = $process->command[3];
        File::put($this->manifestPath, '{"changed":"after-admission"}');
        expect($stagedPath)->not->toBe($this->manifestPath)
            ->and(File::get($stagedPath))->toBe($original)
            ->and(fileperms($stagedPath) & 0777)->toBe(0400);

        return Process::result();
    }]);
    $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $this->manifestPath, '--output' => $this->outputPath])->assertSuccessful();
    expect(File::exists($stagedPath))->toBeFalse()
        ->and(ModelRun::sole()->parameters['manifest'])->toBe($this->manifest)
        ->and(ModelRun::sole()->metadata['input_manifest_sha256'])->toBe(hash('sha256', $original));
});

it('refuses a changed staged manifest before accepting the child output', function () {
    $stagedPath = null;
    Process::fake(['*' => function (PendingProcess $process) use (&$stagedPath) {
        $stagedPath = $process->command[3];
        chmod($stagedPath, 0600);
        File::put($stagedPath, '{"changed":"inside-the-child"}');

        return Process::result();
    }]);
    $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $this->manifestPath, '--output' => $this->outputPath])->assertFailed();
    expect(File::exists($stagedPath))->toBeFalse()->and(ModelRun::sole()->status)->toBe('failed')
        ->and(ModelArtifact::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('cfb/models');
});

it('refuses incomplete trainer report objects before archiving output', function (string $missing) {
    $payload = json_decode(File::get($this->outputPath), true, 64, JSON_THROW_ON_ERROR);
    unset($payload[$missing]);
    File::put($this->outputPath, json_encode($payload, JSON_THROW_ON_ERROR));
    $this->artisan('cfb:train-scoring-challenger', ['--manifest' => $this->manifestPath, '--output' => $this->outputPath])->assertFailed();
    expect(ModelArtifact::count())->toBe(0)->and(ModelRun::sole()->status)->toBe('failed');
    Storage::disk('local')->assertDirectoryEmpty('cfb/models');
})->with(['holdout', 'promotion_report']);
