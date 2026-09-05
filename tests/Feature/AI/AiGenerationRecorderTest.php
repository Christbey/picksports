<?php

use App\Models\AiGeneration;
use App\Services\AI\AiGenerationRecorder;

it('records generative lineage without persisting prompt or output documents', function () {
    $recorder = app(AiGenerationRecorder::class);
    $input = ['game' => ['id' => 'evt_123'], 'signals' => ['edge' => 0.12]];
    $output = ['summary' => 'Home side has a modest edge.', 'classification' => 'lean'];

    $generation = $recorder->start(
        purpose: 'Daily Prediction Analysis',
        promptVersion: 'daily-prediction-v2',
        provider: 'OpenAI',
        model: 'gpt-5-mini',
        input: $input,
        contextType: 'prediction',
        contextId: '01JTESTPREDICTION0000000000',
        metadata: ['queue' => 'ai'],
    );

    expect($generation->status)->toBe('running')
        ->and($generation->input_hash)->toBe($recorder->payloadHash($input));

    $generation = $recorder->complete(
        generation: $generation,
        output: $output,
        latencyMs: 1275,
        tokens: ['input' => 850, 'output' => 140, 'cached_input' => 300],
        costUsd: '0.004250',
        metadata: ['request_id' => 'req_test'],
    );

    expect($generation->status)->toBe('completed')
        ->and($generation->output_hash)->toBe($recorder->payloadHash($output))
        ->and($generation->input_tokens)->toBe(850)
        ->and($generation->output_tokens)->toBe(140)
        ->and($generation->cached_input_tokens)->toBe(300)
        ->and($generation->cost_usd)->toBe('0.004250')
        ->and($generation->latency_ms)->toBe(1275)
        ->and($generation->metadata)->toMatchArray(['queue' => 'ai', 'request_id' => 'req_test']);

    $row = AiGeneration::query()->firstOrFail()->getAttributes();
    expect(json_encode($row))->not->toContain('Home side has a modest edge.')
        ->not->toContain('signals');
});

it('records failures and makes generation outcomes immutable through the recorder', function () {
    $recorder = app(AiGenerationRecorder::class);
    $generation = $recorder->start(
        purpose: 'model_audit',
        promptVersion: 'audit-v1',
        provider: 'openai',
        model: 'gpt-5-mini',
        input: ['prediction' => 42],
    );

    $failed = $recorder->fail($generation, 'provider_rate_limited', 2100);

    expect($failed->status)->toBe('failed')
        ->and($failed->error_code)->toBe('provider_rate_limited')
        ->and($failed->latency_ms)->toBe(2100)
        ->and($failed->completed_at)->not->toBeNull();

    expect(fn () => $recorder->complete($failed, ['result' => true], 1))
        ->toThrow(LogicException::class, 'Only running AI generations can be completed.');
});

it('hashes equivalent associative payloads identically', function () {
    $recorder = app(AiGenerationRecorder::class);

    expect($recorder->payloadHash(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]))
        ->toBe($recorder->payloadHash(['a' => ['x' => 1, 'y' => 2], 'b' => 2]));
});
