<?php

use App\Services\MLB\MlbTabularJsonBudget;

it('decodes bounded JSON with escaped delimiters and exact large integers', function () {
    $raw = '{"text":'.json_encode('a"[{b').',"value":9223372036854775808,"nested":[null,false,0]}';
    expect(MlbTabularJsonBudget::decode($raw, 1024))->toBe([
        'text' => 'a"[{b', 'value' => '9223372036854775808', 'nested' => [null, false, 0],
    ]);
});

it('refuses allocation hazards before decoding small but expansive JSON', function (string $raw, int $bytes, int $tokens) {
    expect(fn () => MlbTabularJsonBudget::decode($raw, $bytes, $tokens))->toThrow(RuntimeException::class);
})->with([
    'byte limit' => ['{"data":"'.str_repeat('a', 1024).'"}', 1024, 20000],
    'tiny node expansion' => ['['.implode(',', array_fill(0, 20000, 0)).']', 262144, 20000],
    'depth limit' => [str_repeat('[', 33).'0'.str_repeat(']', 33), 1024, 20000],
    'unterminated string' => ['{"a":"text}', 1024, 20000],
    'unbalanced nesting' => ['[0', 1024, 20000],
]);

it('leaves invalid scalar grammar to the strict decoder', function (string $raw) {
    expect(fn () => MlbTabularJsonBudget::decode($raw, 1024))->toThrow(JsonException::class);
})->with(['{"x":NaN}', '[1,]', '{"x":01}', 'undefined']);
