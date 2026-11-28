<?php

use App\Services\MLB\MlbTabularJsonBudget;

it('admits exact scalar escaping and structure before whole JSON encoding', function () {
    $value = ['escaped' => "\"\\/\n\t\x01é\u{2028}", 'numbers' => [0, 1.0, -0.0, 1.0e20, null, true, false], 'map' => [1 => 'one', 3 => 'three']];
    $encoded = MlbTabularJsonBudget::encode($value, 2048, 100, 8);
    expect(json_decode($encoded, true, 8, JSON_THROW_ON_ERROR))->toEqual($value);
    expect(fn () => MlbTabularJsonBudget::encode($value, strlen($encoded) - 1, 100, 8))
        ->toThrow(RuntimeException::class, 'before encoding');
});

it('refuses escaped byte expansion before allocating its complete JSON body', function () {
    expect(fn () => MlbTabularJsonBudget::encode(['value' => str_repeat("\x01", 400000)], 2097152, 100, 8))
        ->toThrow(RuntimeException::class, 'byte/token budget exceeded before encoding');
});

it('refuses structural amplification and recursive arrays before encoding', function () {
    expect(fn () => MlbTabularJsonBudget::encode(array_fill(0, 30000, 0), 2097152, 50000, 24))
        ->toThrow(RuntimeException::class, 'before encoding');
    $cycle = [];
    $cycle['self'] = &$cycle;
    expect(fn () => MlbTabularJsonBudget::encode($cycle, 2097152, 50000, 24))
        ->toThrow(RuntimeException::class, 'before encoding');
});

it('never invokes object serialization hooks to prepare evidence JSON', function () {
    $object = new class implements JsonSerializable
    {
        public bool $called = false;

        public function jsonSerialize(): mixed
        {
            $this->called = true;

            return [];
        }
    };
    expect(fn () => MlbTabularJsonBudget::encode(['value' => $object], 2097152))
        ->toThrow(RuntimeException::class, 'plain scalar/array');
    expect($object->called)->toBeFalse();
    expect(fn () => MlbTabularJsonBudget::encode(['value' => INF], 2097152))
        ->toThrow(RuntimeException::class, 'finite plain');
});
