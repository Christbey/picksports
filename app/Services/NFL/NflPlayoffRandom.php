<?php

namespace App\Services\NFL;

use Random\Engine\Mt19937;
use Random\Randomizer;

class NflPlayoffRandom
{
    private readonly Randomizer $outcomes;

    private readonly Randomizer $scores;

    public function __construct(int $seed)
    {
        $this->outcomes = new Randomizer(new Mt19937($seed));
        $this->scores = new Randomizer(new Mt19937($this->outcomeDraw()));
    }

    public function wins(float $probability): bool
    {
        $draw = $this->outcomeDraw() / 0x7FFFFFFF;

        // Retain the historical draw stream while making probability one certain.
        return $probability >= 1.0 || $draw < $probability;
    }

    public function scoreIndex(int $count): int
    {
        return $this->scores->getInt(0, $count - 1);
    }

    public function tiebreakIndex(int $count): int
    {
        return $this->outcomes->getInt(0, $count - 1);
    }

    private function outcomeDraw(): int
    {
        // The previous mt_rand() path used the high 31 bits of each 32-bit draw.
        return $this->outcomes->getInt(0, 0xFFFFFFFF) >> 1;
    }
}
