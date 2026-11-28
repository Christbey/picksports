<?php

use App\Models\AiGeneration;
use App\Services\AI\AiGenerationRecorder;
use Illuminate\Support\Facades\DB;

it('updates the supplied generation on its named connection without changing the default row', function (string $operation) {
    config(['database.connections.ai_recorder_aux' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    $schema = DB::connection('sqlite')->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'ai_generations'")->sql;
    DB::connection('ai_recorder_aux')->statement($schema);
    try {
        $default = AiGeneration::factory()->create(['status' => 'running']);
        $target = AiGeneration::factory()->make(['status' => 'running', 'purpose' => 'synthetic_auxiliary']);
        $target->setConnection('ai_recorder_aux')->save();
        expect($target->getKey())->toBe($default->getKey());
        $before = $default->fresh()->getRawOriginal();
        $recorder = app(AiGenerationRecorder::class);
        $result = $operation === 'complete'
            ? $recorder->complete($target, ['synthetic' => true], 1)
            : $recorder->fail($target, 'synthetic_failure', 1);
        expect($default->fresh()->getRawOriginal())->toBe($before)
            ->and($target->fresh()->status)->toBe($operation === 'complete' ? 'completed' : 'failed')
            ->and($result->getConnectionName())->toBe('ai_recorder_aux');
    } finally {
        DB::purge('ai_recorder_aux');
    }
})->with(['complete', 'fail'])
    ->skip(fn () => config('database.default') !== 'sqlite', 'Explicit SQLite alternate-connection contract.')
    ->group('sqlite-engine-only-contract');

it('refuses invalid measured usage without fabricating zero or completing the generation', function (array $tokens, ?string $cost, int $latency) {
    $generation = AiGeneration::factory()->create(['status' => 'running']);
    $before = $generation->fresh()->getRawOriginal();
    expect(fn () => app(AiGenerationRecorder::class)->complete($generation, ['synthetic' => true], $latency, $tokens, $cost))
        ->toThrow(InvalidArgumentException::class);
    expect($generation->fresh()->getRawOriginal())->toBe($before);
})->with([
    'negative tokens' => [['input' => -1], null, 1],
    'fractional tokens' => [['input' => 1.5], null, 1],
    'string tokens' => [['output' => '12'], null, 1],
    'overflow tokens' => [['cached_input' => 4294967296], null, 1],
    'negative cost' => [[], '-0.01', 1],
    'cost precision loss' => [[], '0.0000001', 1],
    'cost overflow' => [[], '1000000', 1],
    'nonfinite cost' => [[], 'INF', 1],
    'negative latency' => [[], null, -1],
    'overflow latency' => [[], null, 4294967296],
]);

it('preserves unavailable usage and real zero or exact storage-capacity measurements', function (array $tokens, ?string $cost, int $latency, ?string $expectedCost) {
    $generation = AiGeneration::factory()->create(['status' => 'running', 'completed_at' => null, 'output_hash' => null]);
    $result = app(AiGenerationRecorder::class)->complete($generation, ['synthetic' => true], $latency, $tokens, $cost);
    expect($result->status)->toBe('completed')
        ->and($result->input_tokens)->toBe($tokens['input'] ?? null)
        ->and($result->output_tokens)->toBe($tokens['output'] ?? null)
        ->and($result->cached_input_tokens)->toBe($tokens['cached_input'] ?? null)
        ->and($result->cost_usd)->toBe($expectedCost)
        ->and($result->latency_ms)->toBe($latency);
})->with([
    'unknown is not zero' => [[], null, 0, null],
    'actual zero' => [['input' => 0, 'output' => 0, 'cached_input' => 0], '000.0', 0, '0.000000'],
    'exact maximum capacity' => [['input' => 4294967295, 'output' => 4294967295, 'cached_input' => 4294967295], '999999.999999', 4294967295, '999999.999999'],
]);
