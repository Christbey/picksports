<?php

use App\Services\MLB\MlbTabularJsonBudget;
use App\Support\Json\JsonAllocationBudget;

it('retains token depth and escaping semantics independently of domain labels', function () {
    $raw = '{"a":[0,"[]",null]}';
    expect(JsonAllocationBudget::inspect($raw, strlen($raw), 11, 2))->toBe(11)
        ->and(MlbTabularJsonBudget::inspect($raw, strlen($raw), 11, 2))->toBe(11);
    expect(JsonAllocationBudget::inspect(json_encode(['a' => 'quotes"and[]{}']), 128))->toBe(5);
});

it('refuses structural allocation excess with its requested label', function (string $raw, int $bytes, int $tokens, int $depth, string $reason) {
    expect(fn () => JsonAllocationBudget::inspect($raw, $bytes, $tokens, $depth, 'Training manifest JSON'))
        ->toThrow(RuntimeException::class, 'Training manifest JSON '.$reason);
})->with([
    ['[] ', 2, 10, 4, 'byte budget'],
    ['[0]', 32, 2, 4, 'token budget'],
    ['[[]]', 32, 10, 1, 'depth budget'],
]);

it('leaves full grammar validation to the decoder', function () {
    expect(JsonAllocationBudget::inspect('[}', 2))->toBe(2);
    expect(fn () => json_decode('[}', true, 32, JSON_THROW_ON_ERROR))->toThrow(JsonException::class);
    expect(fn () => MlbTabularJsonBudget::inspect('}', 16))->toThrow(RuntimeException::class, 'Malformed MLB JSON structure.');
});
