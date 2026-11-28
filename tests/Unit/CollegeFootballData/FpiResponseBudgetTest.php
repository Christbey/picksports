<?php

use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\CollegeFootballData\Fpi\BoundedFpiSink;
use App\Support\Json\JsonAllocationBudget;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['services.collegefootballdata.api_key' => 'synthetic-fpi-budget-only', 'cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
});

it('bounds bytes written to the actual response sink before accepting another chunk', function () {
    $inner = Utils::streamFor(fopen('php://temp/maxmemory:262144', 'w+'));
    $sink = new BoundedFpiSink($inner);
    try {
        expect($sink->write(str_repeat('x', 2097153)))->toBe(2097153);
        expect(fn () => $sink->write('x'))->toThrow(RuntimeException::class);
        expect($inner->getSize())->toBe(2097153);
    } finally {
        $sink->close();
    }
    expect($inner->isReadable())->toBeFalse();
});

it('accepts the exact raw response byte ceiling and rejects one additional byte before decode', function (bool $excess) {
    $raw = '[]'.str_repeat(' ', 2097152 - 2 + ($excess ? 1 : 0));
    Http::fake(['*/ratings/fpi*' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);
    $service = app(CollegeFootballDataService::class);
    if ($excess) {
        expect(fn () => $service->getFpiSeasonSnapshot(2026))->toThrow(RuntimeException::class, 'byte');
    } else {
        expect($service->getFpiSeasonSnapshot(2026)['rows'])->toBe([]);
    }
    Http::assertSentCount(1);
})->with([false, true]);

it('admits exact lexical token budget but refuses the next token before decoding', function (bool $excess) {
    $items = array_fill(0, 49994, '0');
    $items[0] = '{}';
    if ($excess) {
        $items[1] = '{}';
    }
    $raw = '[{"team":"Token College","padding":['.implode(',', $items).']}]';
    expect(JsonAllocationBudget::inspect($raw, 2097152, 200000, 16))->toBe($excess ? 100001 : 100000);
    Http::fake(['*/ratings/fpi*' => Http::response($raw)]);
    if ($excess) {
        expect(fn () => app(CollegeFootballDataService::class)->getFpiSeasonSnapshot(2026))->toThrow(RuntimeException::class, 'token');
    } else {
        expect(app(CollegeFootballDataService::class)->getFpiSeasonSnapshot(2026)['rows'])->toHaveCount(1);
    }
})->with([false, true]);

it('preserves the depth boundary while refusing deeper and non-list response documents', function (string $kind) {
    $depth = $kind === 'deep' ? 15 : 14;
    $raw = $kind === 'object' ? '{}' : '[{"team":"Depth College","padding":'.str_repeat('[', $depth).'0'.str_repeat(']', $depth).'}]';
    Http::fake(['*/ratings/fpi*' => Http::response($raw)]);
    if ($kind === 'valid') {
        expect(app(CollegeFootballDataService::class)->getFpiSeasonSnapshot(2026)['rows'])->toHaveCount(1);
    } else {
        expect(fn () => app(CollegeFootballDataService::class)->getFpiSeasonSnapshot(2026))->toThrow(RuntimeException::class);
    }
})->with(['valid', 'deep', 'object']);

it('preserves bounded raw transport retries before returning an uncached source observation', function () {
    Http::fake(['*/ratings/fpi*' => Http::sequence()->push([], 500)->push([['team' => 'Retry College', 'fpi' => 12]], 200)]);
    $first = app(CollegeFootballDataService::class)->getFpiSeasonSnapshot(2026);
    expect($first['rows'])->toBe([['team' => 'Retry College', 'fpi' => 12]])->and($first['observed_at'])->not->toBeEmpty();
    Http::assertSentCount(2);
});

it('fetches raw FPI snapshots from the actual configured provider endpoint', function () {
    Http::fake(['https://first.example.test/*' => Http::response([['team' => 'Endpoint College', 'fpi' => 1]]),
        'https://second.example.test/*' => Http::response([['team' => 'Endpoint College', 'fpi' => 2]])]);
    config(['services.collegefootballdata.base_url' => 'https://first.example.test']);
    $first = (new CollegeFootballDataService)->getFpiSeasonSnapshot(2026);
    config(['services.collegefootballdata.base_url' => 'https://second.example.test']);
    $second = (new CollegeFootballDataService)->getFpiSeasonSnapshot(2026);
    expect($first['rows'][0]['fpi'])->toBe(1)->and($second['rows'][0]['fpi'])->toBe(2);
    Http::assertSentCount(2);
});
