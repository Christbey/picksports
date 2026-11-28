<?php

use App\Services\NFL\NflPlayoffRandom;
use Random\Engine;
use Random\Randomizer;

it('preserves certain and impossible outcomes at both extreme draws', function (string $bytes, float $probability, bool $expected) {
    $engine = new class($bytes) implements Engine
    {
        public function __construct(private readonly string $bytes) {}

        public function generate(): string
        {
            return $this->bytes;
        }
    };
    $reflection = new ReflectionClass(NflPlayoffRandom::class);
    $random = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('outcomes')->setValue($random, new Randomizer($engine));
    expect($random->wins($probability))->toBe($expected);
})->with([
    'certain maximum draw' => ["\xff\xff\xff\xff", 1.0, true],
    'impossible maximum draw' => ["\xff\xff\xff\xff", 0.0, false],
    'certain minimum draw' => ["\x00\x00\x00\x00", 1.0, true],
    'impossible minimum draw' => ["\x00\x00\x00\x00", 0.0, false],
]);

it('keeps score sampling separate from reproducible seeded outcomes', function () {
    $first = new NflPlayoffRandom(42);
    $second = new NflPlayoffRandom(42);
    $firstOutcomes = $secondOutcomes = [];
    for ($i = 0; $i < 100; $i++) {
        $first->scoreIndex(20);
        $firstOutcomes[] = $first->wins(0.5);
        $secondOutcomes[] = $second->wins(0.5);
    }
    expect($firstOutcomes)->toBe($secondOutcomes)
        ->and(array_unique($firstOutcomes, SORT_REGULAR))->toHaveCount(2);
});
