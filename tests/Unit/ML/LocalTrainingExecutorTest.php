<?php

use App\Services\ML\LocalTrainingExecutor;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->oldExecutorSentinel = getenv('PICKSPORTS_EXECUTOR_ASSERTION_SECRET');
    putenv('PICKSPORTS_EXECUTOR_ASSERTION_SECRET=synthetic-process-boundary');
    $this->directory = sys_get_temp_dir().'/picksports-executor-'.Str::uuid();
    $this->runId = (string) Str::uuid();
    $this->hash = str_repeat('a', 64);
    $this->specification = [
        'python_command' => ['python3', '-m', 'picksports_nfl_ml'],
        'schema_path' => '/fixtures/schema.yaml',
        'package_directory' => '/fixtures/package',
        'timeout_seconds' => 14400,
        'threads' => 2,
        'tune' => false,
        'explain' => false,
        'model_type' => 'nfl_tabular_bundle',
    ];
    $this->manifest = [
        'model_run_id' => $this->runId,
        'dataset_hash' => $this->hash,
        'model_type' => 'nfl_tabular_bundle',
    ];
    $this->writeManifest = function (mixed $manifest): void {
        File::ensureDirectoryExists($this->directory.'/'.$this->runId);
        File::put($this->directory.'/'.$this->runId.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    };
    $this->execute = fn (string $sport = 'nfl') => app(LocalTrainingExecutor::class)->execute(
        $sport, $this->runId, '/fixtures/data.csv', $this->hash, $this->directory, $this->specification,
    );
    Process::preventStrayProcesses();
});

afterEach(function () {
    putenv($this->oldExecutorSentinel === false ? 'PICKSPORTS_EXECUTOR_ASSERTION_SECRET' : 'PICKSPORTS_EXECUTOR_ASSERTION_SECRET='.$this->oldExecutorSentinel);
    File::deleteDirectory($this->directory);
});

it('preserves the process arguments limits and verified run directory', function (bool $enabled) {
    $this->specification['tune'] = $enabled;
    $this->specification['explain'] = $enabled;
    ($this->writeManifest)($this->manifest);
    Process::fake();

    expect(($this->execute)())->toBe($this->directory.'/'.$this->runId);

    $expectedEnvironment = [
        'OMP_NUM_THREADS' => '2', 'OPENBLAS_NUM_THREADS' => '2',
        'MKL_NUM_THREADS' => '2', 'NUMEXPR_NUM_THREADS' => '2',
        'PYTHONPATH' => '/fixtures/package/src', 'PYTHONUNBUFFERED' => '1',
        'PYTHONUTF8' => '1', 'PYTHONNOUSERSITE' => '1', 'PYTHONDONTWRITEBYTECODE' => '1',
        'LANG' => 'C',
    ];
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'python3', '-m', 'picksports_nfl_ml', 'train',
        '--input', '/fixtures/data.csv', '--schema', '/fixtures/schema.yaml',
        '--expected-dataset-sha256', $this->hash, '--output-dir', $this->directory,
        '--run-id', $this->runId,
        $enabled ? '--tune' : '--no-tune', $enabled ? '--explain' : '--no-explain',
    ] && $process->path === '/fixtures/package'
        && $process->timeout === 14400
        && collect($expectedEnvironment)->every(fn (string $value, string $key): bool => ($process->environment[$key] ?? null) === $value)
        && ($process->environment['PICKSPORTS_EXECUTOR_ASSERTION_SECRET'] ?? null) === false);
})->with([false, true]);

it('omits NFL options for MLB training', function () {
    $this->specification['python_command'] = ['python3', '-m', 'picksports_mlb_ml'];
    $this->specification['model_type'] = 'mlb_tabular_bundle';
    $this->manifest['model_type'] = 'mlb_tabular_bundle';
    ($this->writeManifest)($this->manifest);
    Process::fake();

    ($this->execute)('mlb');

    Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, 0, 3) === ['python3', '-m', 'picksports_mlb_ml']
        && ! in_array('--no-tune', $process->command, true)
        && ! in_array('--no-explain', $process->command, true));
});

it('reports nonzero exits preferring stderr with stdout as fallback', function (string $stderr, string $expected) {
    Process::fake(['*' => Process::result(output: 'stdout detail', errorOutput: $stderr, exitCode: 7)]);

    expect(fn () => ($this->execute)())->toThrow(RuntimeException::class, 'NFL Python training failed with exit code 7: '.$expected);
})->with([['stderr detail', 'stderr detail'], ['', 'stdout detail']]);

it('propagates process failures before accepting even an existing manifest', function () {
    ($this->writeManifest)($this->manifest);
    $process = new Symfony\Component\Process\Process(['python3']);
    $process->setTimeout(14400);
    $failure = new ProcessTimedOutException(
        new Symfony\Component\Process\Exception\ProcessTimedOutException($process, Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL),
        Process::result(exitCode: 1),
    );
    Process::fake(['*' => function () use ($failure) {
        throw $failure;
    }]);

    try {
        ($this->execute)();
        test()->fail('Expected process failure');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }
});

it('rejects a successful process without a manifest', function () {
    Process::fake();

    expect(fn () => ($this->execute)())->toThrow(RuntimeException::class, 'expected immutable run directory');
});

it('rejects structurally incomplete manifest JSON before decoding', function () {
    ($this->writeManifest)($this->manifest);
    File::put($this->directory.'/'.$this->runId.'/manifest.json', '{');
    Process::fake();

    expect(fn () => ($this->execute)())->toThrow(RuntimeException::class, 'Malformed Training manifest JSON structure.');
});

it('keeps the JSON decoder authoritative for balanced malformed manifest syntax', function () {
    ($this->writeManifest)($this->manifest);
    File::put($this->directory.'/'.$this->runId.'/manifest.json', '{"key":}');
    Process::fake();

    expect(fn () => ($this->execute)())->toThrow(JsonException::class);
});

it('rejects mismatched missing and nonstring lineage fields', function (string $field, mixed $value) {
    $this->manifest[$field] = $value;
    ($this->writeManifest)($this->manifest);
    Process::fake();

    expect(fn () => ($this->execute)())->toThrow(RuntimeException::class, "Python manifest {$field} does not match the requested training lineage.");
})->with([
    ['model_run_id', 'wrong'], ['dataset_hash', 'wrong'], ['model_type', 'wrong'],
    ['model_run_id', null], ['dataset_hash', 123], ['model_type', null],
]);

it('observes process output while preserving successful execution and manifest verification', function () {
    ($this->writeManifest)($this->manifest);
    // Laravel synchronous process fakes do not invoke output callbacks; exercise a tiny real PHP child.
    Process::swap(new Factory);
    $this->specification['python_command'] = [PHP_BINARY, '-r', 'fwrite(STDOUT, "training output");', '--'];
    $this->specification['package_directory'] = $this->directory;
    $this->specification['timeout_seconds'] = 2;
    $output = '';
    $directory = app(LocalTrainingExecutor::class)->execute(
        'nfl', $this->runId, '/fixtures/data.csv', $this->hash, $this->directory, $this->specification,
        function (string $type, string $chunk) use (&$output): void {
            $output .= $chunk;
        },
    );
    expect($directory)->toBe($this->directory.'/'.$this->runId)
        ->and($output)->toContain('training output');
});
