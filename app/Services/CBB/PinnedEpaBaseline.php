<?php

namespace App\Services\CBB;

/** Frozen numeric input only; this object grants no terminal or database authority. */
final readonly class PinnedEpaBaseline
{
    public array $map;

    public function __construct(public int $gameId, public int $season, array $map, public string $sourceHash)
    {
        if ($gameId <= 0 || $season <= 0 || count($map) > 4096 || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
            throw new \RuntimeException('CBB pinned baseline identity is unavailable.');
        }
        $detached = [];
        foreach ($map as $key => $value) {
            if (! is_string($key) || strlen($key) > 128 || (! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new \RuntimeException('CBB pinned baseline values are unavailable.');
            }
            $detached[$key] = $value;
        }
        $this->map = $detached;
    }
}
