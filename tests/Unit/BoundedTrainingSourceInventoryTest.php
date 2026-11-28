<?php

use App\Support\BoundedTrainingSourceInventory;
use Illuminate\Filesystem\Filesystem;

function boundedSourceUnitDirectory(): string
{
    $root = realpath(sys_get_temp_dir()).'/picksports-bounded-source-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);

    return $root;
}

it('refuses aliases of a symbolic source root', function (string $suffix) {
    $root = boundedSourceUnitDirectory();
    mkdir($root.'/target', 0700);
    file_put_contents($root.'/target/known.py', '# synthetic');
    symlink($root.'/target', $root.'/link');
    try {
        expect(fn () => BoundedTrainingSourceInventory::capture($root.'/link'.$suffix))->toThrow(RuntimeException::class);
    } finally {
        unlink($root.'/link');
        (new Filesystem)->deleteDirectory($root);
    }
})->with(['', '/', '//', '/.', '/./', '/./.']);

it('preserves the normal weekly fingerprint and existing hidden and VCS policy', function () {
    $root = boundedSourceUnitDirectory();
    foreach (['src', 'config', '.hidden', 'CVS', '_svn', '_darcs'] as $directory) {
        mkdir($root.'/'.$directory);
    }
    foreach (['Dockerfile' => 'FROM synthetic', 'pyproject.toml' => '# config', 'requirements.lock.txt' => '',
        'src/é.py' => '# unicode', 'src/10.PY' => '# upper extension', 'src/2.py' => '# lower',
        'config/a.yaml' => 'key: 1', '.hidden/hidden.py' => '# ignored', 'CVS/ignored.py' => '# ignored', '_svn/ignored.py' => '# ignored', '_darcs/ignored.py' => '# ignored'] as $path => $body) {
        file_put_contents($root.'/'.$path, $body);
    }
    try {
        $select = static fn (string $path): bool => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['py', 'yaml', 'yml', 'toml', 'txt'], true);
        $legacy = collect((new Filesystem)->allFiles($root))->filter(fn ($file) => $select($file->getRelativePathname()))
            ->sortBy(fn ($file) => $file->getRelativePathname())
            ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => hash_file('sha256', $file->getPathname())])->all();
        $set = BoundedTrainingSourceInventory::capture($root, $select);
        expect($set->jsonMapHash())->toBe(hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)))
            ->and(array_keys($set->descriptors()))->toBe(array_keys($legacy))
            ->and($set->read('requirements.lock.txt'))->toBe('');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('preserves ordered path NUL raw-byte remote hashing', function () {
    $root = boundedSourceUnitDirectory();
    mkdir($root.'/src');
    mkdir($root.'/config');
    $paths = ['Dockerfile', 'pyproject.toml', 'requirements.lock.txt', 'config/a.yaml', 'src/a.py', 'src/é.py'];
    foreach ($paths as $index => $path) {
        file_put_contents($root.'/'.$path, 'synthetic '.$index);
    }
    try {
        $expected = hash_init('sha256');
        foreach ($paths as $path) {
            hash_update($expected, $path."\0");
            hash_update_file($expected, $root.'/'.$path);
        }
        $set = BoundedTrainingSourceInventory::capture($root);
        expect($set->concatenatedHash($paths))->toBe(hash_final($expected))
            ->and(BoundedTrainingSourceInventory::hashFile($root.'/config/a.yaml'))->toBe(hash_file('sha256', $root.'/config/a.yaml'));
        expect(fn () => $set->concatenatedHash([new stdClass]))->toThrow(RuntimeException::class);
        expect(fn () => $set->concatenatedHash(['missing.py']))->toThrow(RuntimeException::class);
        expect(fn () => $set->concatenatedHash(['src/a.py', 'src/a.py']))->toThrow(RuntimeException::class);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('refuses source file and aggregate byte excess before body admission', function (string $kind) {
    $root = boundedSourceUnitDirectory();
    $files = $kind === 'single' ? 1 : 17;
    try {
        for ($index = 0; $index < $files; $index++) {
            $handle = fopen($root.'/'.$index.'.py', 'xb');
            ftruncate($handle, BoundedTrainingSourceInventory::MAX_FILE_BYTES + ($kind === 'single' ? 1 : 0));
            fclose($handle);
        }
        expect(fn () => BoundedTrainingSourceInventory::capture($root))->toThrow(RuntimeException::class, 'file or byte bound');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
})->with(['single', 'total']);

it('refuses entry file and depth excess instead of silently truncating', function (string $kind) {
    $root = boundedSourceUnitDirectory();
    try {
        if ($kind === 'depth') {
            $path = $root;
            for ($index = 0; $index < 18; $index++) {
                $path .= '/d';
                mkdir($path);
            }
            file_put_contents($path.'/a.py', '# known');
        } else {
            $count = $kind === 'entries' ? 513 : 129;
            for ($index = 0; $index < $count; $index++) {
                if ($kind === 'entries') {
                    mkdir($root.'/d'.$index);
                } else {
                    file_put_contents($root.'/'.$index.'.py', '');
                }
            }
        }
        expect(fn () => BoundedTrainingSourceInventory::capture($root))->toThrow(RuntimeException::class);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
})->with(['entries', 'files', 'depth']);

it('refuses changed bytes and membership after capture', function () {
    $root = boundedSourceUnitDirectory();
    file_put_contents($root.'/a.py', 'first');
    try {
        $set = BoundedTrainingSourceInventory::capture($root);
        file_put_contents($root.'/a.py', 'other');
        expect(fn () => $set->read('a.py'))->toThrow(RuntimeException::class);
        file_put_contents($root.'/a.py', 'first');
        $set = BoundedTrainingSourceInventory::capture($root);
        file_put_contents($root.'/new.py', '# new');
        expect(fn () => $set->concatenatedHash(['a.py']))->toThrow(RuntimeException::class);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('refuses visible symlinks even when a selector would ignore the entry', function () {
    $root = boundedSourceUnitDirectory();
    $outside = $root.'-outside';
    file_put_contents($outside, '# owned external sentinel');
    symlink($outside, $root.'/ignored.bin');
    try {
        expect(fn () => BoundedTrainingSourceInventory::capture($root, static fn () => false))->toThrow(RuntimeException::class);
    } finally {
        unlink($root.'/ignored.bin');
        unlink($outside);
        (new Filesystem)->deleteDirectory($root);
    }
});

it('bounds encountered entries before filtering hidden names', function () {
    $root = boundedSourceUnitDirectory();
    try {
        for ($index = 0; $index < 513; $index++) {
            file_put_contents($root.'/.ignored'.$index, '');
        }
        expect(fn () => BoundedTrainingSourceInventory::capture($root))->toThrow(RuntimeException::class, 'entry or depth bound');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('retains the exact accepted boundaries and empty fingerprint', function (string $kind) {
    $root = boundedSourceUnitDirectory();
    try {
        if ($kind === 'entries') {
            for ($index = 0; $index < 512; $index++) {
                file_put_contents($root.'/.ignored'.$index, '');
            }
            $set = BoundedTrainingSourceInventory::capture($root);
            expect($set->descriptors())->toBe([])->and($set->jsonMapHash())->toBe(hash('sha256', '[]'));
        } elseif ($kind === 'files') {
            for ($index = 0; $index < 128; $index++) {
                file_put_contents($root.'/'.$index.'.py', '');
            }
            expect(BoundedTrainingSourceInventory::capture($root)->descriptors())->toHaveCount(128);
        } else {
            for ($index = 0; $index < 16; $index++) {
                $handle = fopen($root.'/'.$index.'.py', 'xb');
                ftruncate($handle, BoundedTrainingSourceInventory::MAX_FILE_BYTES);
                fclose($handle);
            }
            $descriptors = BoundedTrainingSourceInventory::capture($root)->descriptors();
            expect($descriptors)->toHaveCount(16)
                ->and(array_sum(array_column($descriptors, 'bytes')))->toBe(BoundedTrainingSourceInventory::MAX_TOTAL_BYTES);
        }
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
})->with(['entries', 'files', 'bytes']);

it('handles a relative directory named zero without changing its root', function () {
    $root = boundedSourceUnitDirectory();
    $previous = getcwd();
    mkdir($root.'/0');
    file_put_contents($root.'/0/a.py', '# zero');
    try {
        chdir($root);
        expect(array_keys(BoundedTrainingSourceInventory::capture('0')->descriptors()))->toBe(['a.py']);
    } finally {
        chdir($previous);
        (new Filesystem)->deleteDirectory($root);
    }
});

it('refuses a preexisting nonregular source before attempting a read', function () {
    if (! function_exists('posix_mkfifo')) {
        $this->markTestSkipped('POSIX FIFO fixture unavailable.');
    }
    $root = boundedSourceUnitDirectory();
    posix_mkfifo($root.'/known.pipe', 0600);
    try {
        expect(fn () => BoundedTrainingSourceInventory::capture($root))->toThrow(RuntimeException::class, 'regular files or directories');
    } finally {
        unlink($root.'/known.pipe');
        (new Filesystem)->deleteDirectory($root);
    }
});
