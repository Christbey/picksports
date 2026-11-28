<?php

use App\Services\ESPN\BaseEspnService;
use App\Services\ProviderData\Refresh\RefreshBudget;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

function isolatedProviderResource(string $mode): array
{
    $process = new Process([PHP_BINARY, '-d', $mode === 'elevated-parent' ? 'memory_limit=256M' : 'memory_limit=128M',
        base_path('tests/Fixtures/provider-refresh-resource-worker.php'), $mode], base_path(),
        ['APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(), 'LARAVEL_STORAGE_PATH' => storage_path(),
            'DB_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
    $process->setTimeout(30)->mustRun();

    return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
}

it('rejects dense valid JSON under a fresh 128 MiB ceiling despite an elevated parent peak', function () {
    $result = isolatedProviderResource('elevated-parent');
    expect($result['raw_bytes'])->toBeLessThanOrEqual($result['response_limit'])
        ->and($result['rejected'])->toBeTrue()->and($result['sent_count'])->toBe(1)
        ->and($result['memory_limit'])->toBe('128M')
        ->and($result['peak_bytes'])->toBeLessThanOrEqual(128 * 1024 * 1024)
        ->and($result['parent_peak_bytes'])->toBeGreaterThan(128 * 1024 * 1024);
});

it('rejects an oversized raw response before consumer JSON materialization', function () {
    $budget = new RefreshBudget;
    $body = str_repeat(' ', $budget->responseLimit() + 1);
    Http::fake(['*' => Http::response($body)]);
    $service = new BaseEspnService('nfl');
    $service->swapRefreshBudget($budget);
    expect($service->getByRef('https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/injuries/1', false))->toBeNull();
    Http::assertSentCount(1);
});

it('enforces a cumulative byte budget across individually valid responses', function () {
    $budget = new RefreshBudget;
    $body = '{"padding":"'.str_repeat('x', $budget->responseLimit() - 14).'"}';
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
    $service = new BaseEspnService('nfl');
    $service->swapRefreshBudget($budget);
    foreach (range(1, 4) as $id) {
        expect($service->getByRef('https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/injuries/'.$id, false))->toBeArray();
    }
    expect($service->getByRef('https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/injuries/5', false))->toBeNull();
    Http::assertSentCount(5);
});

it('refuses the 101st reference before HTTP and preserves the first hundred bounded acquisitions', function () {
    $budget = new RefreshBudget;
    foreach (range(1, 100) as $number) {
        $budget->reference();
    }
    expect(fn () => $budget->reference())->toThrow(RuntimeException::class, 'reference limit');
});

it('refuses an elapsed aggregate deadline before any network request', function () {
    Http::preventStrayRequests();
    $budget = new RefreshBudget;
    (new ReflectionProperty($budget, 'deadline'))->setValue($budget, hrtime(true) / 1e9 - 1);
    $service = new BaseEspnService('nfl');
    $service->swapRefreshBudget($budget);
    expect(fn () => $service->getByRef('https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/injuries/1', false))->toThrow(RuntimeException::class, 'deadline expired');
    Http::assertNothingSent();
});

it('does not let configuration raise the conservative materialization ceiling', function () {
    config(['provider_refresh.maximum_response_bytes' => 20 * 1024 * 1024]);
    expect(fn () => (new RefreshBudget)->responseLimit())->toThrow(RuntimeException::class, 'ceiling');
});

it('materializes an admitted bounded JSON list and retains explicit zero values', function () {
    $result = isolatedProviderResource('admitted');
    expect($result['rejected'])->toBeFalse()->and($result['count'])->toBe(2000)
        ->and($result['last_value'])->toBe(0)->and($result['sent_count'])->toBe(1)
        ->and($result['memory_limit'])->toBe('128M')
        ->and($result['peak_bytes'])->toBeLessThanOrEqual(128 * 1024 * 1024);
});

it('rejects excessive JSON nesting and counts punctuation only outside quoted strings', function () {
    $budget = new RefreshBudget;
    $budget->consumeRaw(json_encode(['literal' => str_repeat('[]{},:', 20000)], JSON_THROW_ON_ERROR));
    expect(fn () => $budget->consumeRaw(str_repeat('[', 65).'0'.str_repeat(']', 65)))->toThrow(RuntimeException::class, 'depth limit');
});

it('rejects a cumulative token budget even when each response is individually admitted', function () {
    $budget = new RefreshBudget;
    $body = '['.implode(',', array_fill(0, 15000, '0')).']';
    $budget->consumeRaw($body);
    $budget->consumeRaw($body);
    expect(fn () => $budget->consumeRaw($body))->toThrow(RuntimeException::class, 'token limit');
});
