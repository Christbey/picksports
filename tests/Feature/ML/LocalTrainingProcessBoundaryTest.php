<?php

use App\Services\ML\LocalTrainingExecutor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function localTrainingBoundaryFixture(string $mode): array
{
    $root = realpath(sys_get_temp_dir()).'/picksports-training-process-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root.'/src', 0700);
    file_put_contents($root.'/dataset.csv', "fixture\n1\n");
    file_put_contents($root.'/schema.yaml', "fixture: true\n");
    $script = <<<'PHP'
<?php
$mode = __MODE__;
$options = [];
for ($index = 1; $index < count($argv) - 1; $index++) {
    if (str_starts_with($argv[$index], '--')) {
        $options[$argv[$index]] = $argv[$index + 1];
    }
}
$run = $options['--output-dir'].'/'.$options['--run-id'];
mkdir($run, 0700, true);
$manifest = ['model_run_id' => $options['--run-id'], 'dataset_hash' => $options['--expected-dataset-sha256'], 'model_type' => 'synthetic-tabular', 'known_zero' => 0, 'known_null' => null];
if ($mode === 'wrong_lineage') {
    $manifest['dataset_hash'] = str_repeat('0', 64);
} elseif ($mode === 'environment') {
    foreach (['APP_KEY', 'STRIPE_SECRET', 'AWS_SECRET_ACCESS_KEY', 'PICKSPORTS_TRAINING_PRIVATE_SENTINEL'] as $name) {
        $manifest['sentinel_present'][$name] = getenv($name) !== false;
    }
    $manifest['python_path'] = getenv('PYTHONPATH');
    $manifest['threads'] = getenv('OMP_NUM_THREADS');
    $manifest['unbuffered'] = getenv('PYTHONUNBUFFERED');
    $manifest['code_version'] = getenv('PICKSPORTS_CODE_VERSION');
} elseif ($mode === 'large') {
    $manifest['unused'] = str_repeat('x', 2097153);
} elseif ($mode === 'tokens') {
    $manifest['unused'] = array_fill(0, 60000, []);
} elseif ($mode === 'depth') {
    $value = [];
    for ($index = 0; $index < 30; $index++) {
        $value = [$value];
    }
    $manifest['unused'] = $value;
}
file_put_contents($run.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
PHP;
    file_put_contents($root.'/fixture.php', str_replace('__MODE__', var_export($mode, true), $script));
    $specification = ['python_command' => [PHP_BINARY, $root.'/fixture.php'], 'schema_path' => $root.'/schema.yaml',
        'package_directory' => $root, 'timeout_seconds' => 10, 'threads' => 3, 'model_type' => 'synthetic-tabular', 'tune' => false, 'explain' => false];

    return [$root, $specification];
}

it('removes application and provider environment from the actual local training child', function (string $sport) {
    [$root, $specification] = localTrainingBoundaryFixture('environment');
    $previous = [];
    foreach (['APP_KEY', 'STRIPE_SECRET', 'AWS_SECRET_ACCESS_KEY', 'PICKSPORTS_TRAINING_PRIVATE_SENTINEL'] as $name) {
        $previous[$name] = getenv($name);
        putenv($name.'=synthetic-private-sentinel');
    }
    $oldCodeVersion = getenv('PICKSPORTS_CODE_VERSION');
    $oldCodeEnv = $_ENV['PICKSPORTS_CODE_VERSION'] ?? null;
    $oldCodeServer = $_SERVER['PICKSPORTS_CODE_VERSION'] ?? null;
    putenv('PICKSPORTS_CODE_VERSION=synthetic-code-version');
    $_ENV['PICKSPORTS_CODE_VERSION'] = $_SERVER['PICKSPORTS_CODE_VERSION'] = 'synthetic-code-version';
    try {
        $run = app(LocalTrainingExecutor::class)->execute($sport, (string) Str::uuid(), $root.'/dataset.csv', hash_file('sha256', $root.'/dataset.csv'), $root.'/output', $specification);
        $manifest = json_decode(file_get_contents($run.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['sentinel_present'])->toBe(array_fill_keys(array_keys($previous), false))
            ->and($manifest['python_path'])->toBe($root.'/src')
            ->and($manifest['threads'])->toBe('3')
            ->and($manifest['unbuffered'])->toBe('1')
            ->and($manifest['code_version'])->toBe('synthetic-code-version');
    } finally {
        putenv($oldCodeVersion === false ? 'PICKSPORTS_CODE_VERSION' : 'PICKSPORTS_CODE_VERSION='.$oldCodeVersion);
        if ($oldCodeEnv === null) {
            unset($_ENV['PICKSPORTS_CODE_VERSION']);
        } else {
            $_ENV['PICKSPORTS_CODE_VERSION'] = $oldCodeEnv;
        }
        if ($oldCodeServer === null) {
            unset($_SERVER['PICKSPORTS_CODE_VERSION']);
        } else {
            $_SERVER['PICKSPORTS_CODE_VERSION'] = $oldCodeServer;
        }
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        File::deleteDirectory($root);
    }
})->with(['mlb', 'nfl']);

it('refuses local manifest allocation excess before decoding', function (string $mode) {
    [$root, $specification] = localTrainingBoundaryFixture($mode);
    try {
        expect(fn () => app(LocalTrainingExecutor::class)->execute('mlb', (string) Str::uuid(), $root.'/dataset.csv', hash_file('sha256', $root.'/dataset.csv'), $root.'/output', $specification))
            ->toThrow(RuntimeException::class);
    } finally {
        File::deleteDirectory($root);
    }
})->with(['large', 'tokens', 'depth']);

it('retains valid lineage and real zero or null metadata without training', function (string $sport) {
    [$root, $specification] = localTrainingBoundaryFixture('valid');
    $id = (string) Str::uuid();
    try {
        $run = app(LocalTrainingExecutor::class)->execute($sport, $id, $root.'/dataset.csv', hash_file('sha256', $root.'/dataset.csv'), $root.'/output', $specification);
        $manifest = json_decode(file_get_contents($run.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['known_zero'])->toBe(0)->and($manifest['known_null'])->toBeNull()->and(basename($run))->toBe($id);
    } finally {
        File::deleteDirectory($root);
    }
})->with(['mlb', 'nfl']);

it('retains refusal of an actual wrong lineage manifest', function () {
    [$root, $specification] = localTrainingBoundaryFixture('wrong_lineage');
    try {
        expect(fn () => app(LocalTrainingExecutor::class)->execute('mlb', (string) Str::uuid(), $root.'/dataset.csv', hash_file('sha256', $root.'/dataset.csv'), $root.'/output', $specification))
            ->toThrow(RuntimeException::class, 'dataset_hash does not match');
    } finally {
        File::deleteDirectory($root);
    }
});
