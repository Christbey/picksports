<?php

use App\Services\ML\CsvDataset;
use Illuminate\Support\Str;

function streamingCsvPath(): string
{
    return sys_get_temp_dir().'/picksports-csv-'.Str::uuid().'.csv';
}

it('consumes a single-use generator and preserves late headers and derived column position', function () {
    $path = streamingCsvPath();
    $headersSeen = [];
    $rows = (function () {
        yield ['first' => "a,\nb", 'metadata' => ['url' => 'https://example.test'], 'flag' => false];
        yield ['late' => 'last', 'first' => 'second', 'flag' => true];
    })();
    $writer = app(CsvDataset::class);
    try {
        $count = $writer->write($path, $rows, ['schema' => function (array $headers) use (&$headersSeen): string {
            $headersSeen = $headers;

            return 'complete';
        }]);
        $actual = $writer->read($path);
        expect($count)->toBe(2)
            ->and($headersSeen)->toBe(['first', 'metadata', 'flag', 'late'])
            ->and(array_keys($actual[0]))->toBe(['first', 'metadata', 'flag', 'schema', 'late'])
            ->and($actual)->toBe([
                ['first' => "a,\nb", 'metadata' => '{"url":"https://example.test"}', 'flag' => '0', 'schema' => 'complete', 'late' => ''],
                ['first' => 'second', 'metadata' => '', 'flag' => '1', 'schema' => 'complete', 'late' => 'last'],
            ]);
    } finally {
        @unlink($path);
    }
});

it('preserves arbitrary scalar CSV bytes through temporary spooling', function () {
    $path = streamingCsvPath();
    $value = "\xffa\0\nquoted\"value";
    $writer = app(CsvDataset::class);
    try {
        expect($writer->write($path, [["\xffheader" => $value, 'empty' => null, 'float' => 1.0]]))->toBe(1)
            ->and($writer->read($path))->toBe([["\xffheader" => $value, 'empty' => '', 'float' => '1']]);
    } finally {
        @unlink($path);
    }
});

it('keeps an existing destination intact if its row producer fails', function () {
    $path = streamingCsvPath();
    file_put_contents($path, 'original file');
    $rows = (function () {
        yield ['first' => 'valid'];
        throw new RuntimeException('producer failed');
    })();
    try {
        expect(fn () => app(CsvDataset::class)->write($path, $rows))->toThrow(RuntimeException::class, 'producer failed')
            ->and(file_get_contents($path))->toBe('original file')
            ->and(glob(dirname($path).'/.'.basename($path).'.*.tmp'))->toBe([]);
    } finally {
        @unlink($path);
    }
});

it('returns zero without publishing an empty iterable', function () {
    $path = streamingCsvPath();
    file_put_contents($path, 'original file');
    $rows = (function () {
        yield from [];
    })();
    try {
        expect(app(CsvDataset::class)->write($path, $rows))->toBe(0)
            ->and(file_get_contents($path))->toBe('original file');
    } finally {
        @unlink($path);
    }
});

it('refuses to overwrite a real column with a derived column', function () {
    $path = streamingCsvPath();
    try {
        expect(fn () => app(CsvDataset::class)->write($path, [['schema' => 'original']], ['schema' => fn () => 'replacement']))
            ->toThrow(RuntimeException::class, 'Derived CSV column already exists')
            ->and(file_exists($path))->toBeFalse();
    } finally {
        @unlink($path);
    }
});
