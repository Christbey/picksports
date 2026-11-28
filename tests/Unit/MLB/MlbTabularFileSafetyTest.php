<?php

use App\Services\MLB\MlbTabularFiles;
use App\Services\MLB\MlbTabularZipSafety;

function mlbSafetyZip(array $entries, ?string $symlink = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'mlb-zip-safety-');
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);
    }
    if ($symlink !== null) {
        $zip->setExternalAttributesName($symlink, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    }
    $zip->close();

    return $path;
}

it('admits ordinary finite classic ZIP metadata without extracting it', function () {
    $path = mlbSafetyZip(['manifest.json' => '{}', 'models/example.bin' => 'synthetic bytes']);
    try {
        MlbTabularZipSafety::inspect($path, 64, 1024, 4096);
        expect(file_exists($path))->toBeTrue();
    } finally {
        unlink($path);
    }
});

it('refuses malformed expansive or unsafe ZIP metadata before libzip inventory loading', function (string $case) {
    $entries = match ($case) {
        'unsafe_path' => ['manifest.json' => '{}', '../escape.bin' => 'bad'],
        'deep_path' => ['manifest.json' => '{}', str_repeat('a/', 10).'x' => 'bad'],
        'long_name' => ['manifest.json' => '{}', str_repeat('a', 257) => 'bad'],
        'duplicates' => ['x1.bin' => 'a', 'x2.bin' => 'b'],
        default => ['manifest.json' => '{}', 'models/example.bin' => 'synthetic bytes'],
    };
    $path = mlbSafetyZip($entries, $case === 'symlink' ? 'models/example.bin' : null);
    try {
        $raw = file_get_contents($path);
        $footer = strrpos($raw, "PK\x05\x06");
        $directory = unpack('Voffset', substr($raw, $footer + 16, 4))['offset'];
        if ($case === 'zip64_count') {
            $raw = substr_replace($raw, pack('vv', 65535, 65535), $footer + 8, 4);
        } elseif ($case === 'directory_budget') {
            $raw = substr_replace($raw, pack('V', 1000000), $footer + 12, 4);
        } elseif ($case === 'entry_byte_budget') {
            $raw = substr_replace($raw, pack('V', 1025), $directory + 24, 4);
        } elseif ($case === 'duplicates') {
            $raw = str_replace('x2.bin', 'x1.bin', $raw);
        }
        file_put_contents($path, $raw);
        expect(fn () => MlbTabularZipSafety::inspect($path, 64, 1024, $case === 'total_byte_budget' ? 1 : 4096))
            ->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with(['unsafe_path', 'deep_path', 'long_name', 'duplicates', 'symlink', 'zip64_count', 'directory_budget', 'entry_byte_budget', 'total_byte_budget']);

it('reads at most the expected byte count plus one from a growing decompression stream', function () {
    $stream = tmpfile();
    fwrite($stream, str_repeat('a', 1048576));
    rewind($stream);
    try {
        expect(fn () => MlbTabularFiles::digest($stream, 32))->toThrow(RuntimeException::class, 'grew')
            ->and(ftell($stream))->toBe(33);
    } finally {
        fclose($stream);
    }
});

it('refuses truncated streams and verifies zero-byte evidence without inventing data', function () {
    $stream = tmpfile();
    try {
        expect(MlbTabularFiles::digest($stream, 0))->toBe(hash('sha256', ''));
        rewind($stream);
        expect(fn () => MlbTabularFiles::digest($stream, 1))->toThrow(RuntimeException::class, 'shorter');
    } finally {
        fclose($stream);
    }
});

it('copies only exact owned bytes and refuses a forged expected hash', function (bool $wrongHash) {
    $source = tempnam(sys_get_temp_dir(), 'mlb-copy-safety-');
    $target = $source.'.copy';
    file_put_contents($source, "synthetic\x00bytes");
    $size = filesize($source);
    $hash = hash_file('sha256', $source);
    try {
        if ($wrongHash) {
            expect(fn () => MlbTabularFiles::copy($source, $target, $size, str_repeat('0', 64)))->toThrow(RuntimeException::class, 'verification');
        } else {
            MlbTabularFiles::copy($source, $target, $size, $hash);
            expect(hash_file('sha256', $target))->toBe($hash)->and(filesize($target))->toBe($size)
                ->and(fileperms($target) & 0777)->toBe(0400);
        }
        expect(hash_file('sha256', $source))->toBe($hash);
    } finally {
        @unlink($source);
        @unlink($target);
    }
})->with([false, true]);
