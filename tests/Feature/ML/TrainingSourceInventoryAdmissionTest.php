<?php

use App\Services\ML\RemoteTrainingPreparation;
use App\Services\ML\WeeklyChallengerTrainingService;
use Illuminate\Support\Facades\File;

function sourceInventoryFixture(): string
{
    $root = sys_get_temp_dir().'/picksports-source-inventory-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root.'/src', 0700);
    mkdir($root.'/config', 0700);
    foreach (['Dockerfile', 'pyproject.toml', 'requirements.lock.txt', 'src/a.py', 'config/a.yaml'] as $file) {
        file_put_contents($root.'/'.$file, 'synthetic known source bytes');
    }

    return $root;
}

function currentWeeklySourceHash(string $root): string
{
    $class = new ReflectionClass(WeeklyChallengerTrainingService::class);

    return $class->getMethod('directoryHash')->invoke($class->newInstanceWithoutConstructor(), $root);
}

function currentRemoteSourceHash(string $root): string
{
    $class = new ReflectionClass(RemoteTrainingPreparation::class);

    return $class->newInstanceWithoutConstructor()->sourceHash('mlb', $root);
}

it('refuses oversized package source before accepting a training fingerprint', function (string $kind) {
    $root = sourceInventoryFixture();
    try {
        file_put_contents($root.'/src/oversized.py', str_repeat('x', 2097153));
        expect(fn () => $kind === 'weekly' ? currentWeeklySourceHash($root) : currentRemoteSourceHash($root))->toThrow(RuntimeException::class);
    } finally {
        File::deleteDirectory($root);
    }
})->with(['weekly', 'remote']);

it('refuses a visible source symlink rather than hashing an external file', function (string $kind) {
    $root = sourceInventoryFixture();
    $outside = $root.'-outside';
    file_put_contents($outside, 'synthetic external sentinel only');
    symlink($outside, $root.'/src/linked.py');
    try {
        expect(fn () => $kind === 'weekly' ? currentWeeklySourceHash($root) : currentRemoteSourceHash($root))->toThrow(RuntimeException::class);
    } finally {
        unlink($root.'/src/linked.py');
        unlink($outside);
        File::deleteDirectory($root);
    }
})->with(['weekly', 'remote']);

it('preserves ordinary caller fingerprint bytes and distinct extension policies', function (string $kind) {
    $root = sourceInventoryFixture();
    try {
        file_put_contents($root.'/src/Z.PY', 'synthetic known source bytes');
        file_put_contents($root.'/src/é.py', 'synthetic known source bytes');
        file_put_contents($root.'/README.md', 'ignored ordinary source document');
        file_put_contents($root.'/.hidden.py', 'ignored hidden source');
        mkdir($root.'/_darcs', 0700);
        file_put_contents($root.'/_darcs/ignored.py', 'ignored legacy VCS source');
        if ($kind === 'weekly') {
            $paths = ['config/a.yaml', 'pyproject.toml', 'requirements.lock.txt', 'src/Z.PY', 'src/a.py', 'src/é.py'];
            $map = array_fill_keys($paths, hash('sha256', 'synthetic known source bytes'));
            $expected = hash('sha256', json_encode($map, JSON_THROW_ON_ERROR));
            expect(currentWeeklySourceHash($root))->toBe($expected);
        } else {
            $hash = hash_init('sha256');
            foreach (['Dockerfile', 'pyproject.toml', 'requirements.lock.txt', 'config/a.yaml', 'src/a.py', 'src/é.py'] as $relative) {
                hash_update($hash, $relative."\0".'synthetic known source bytes');
            }
            expect(currentRemoteSourceHash($root))->toBe(hash_final($hash));
        }
    } finally {
        File::deleteDirectory($root);
    }
})->with(['weekly', 'remote']);
