<?php

namespace App\Services\Research;

use App\Services\Api\V2\SportContextResolver;
use Illuminate\Database\Eloquent\Model;

class ResearchCapabilityRegistry
{
    public function __construct(private readonly SportContextResolver $sports) {}

    public function validate(Model $game, string $sport): string
    {
        $sport = strtolower(trim($sport));
        $context = $this->sports->find($sport);
        $gameClass = $context?->models['game'] ?? null;
        if ($gameClass === null || ! $game instanceof $gameClass || ! is_array(config("research.sports.{$sport}"))) {
            throw new \InvalidArgumentException('Research sport and game identity must match a supported capability.');
        }

        return $sport;
    }

    public function enabled(string $sport): bool
    {
        return (bool) config('research.enabled', false) && (bool) config("research.sports.{$sport}.enabled", false);
    }

    /** @return list<string> */
    public function topics(string $sport): array
    {
        return array_values((array) config("research.sports.{$sport}.topics", []));
    }

    public function freshnessMinutes(string $sport): int
    {
        return max(1, (int) config("research.sports.{$sport}.freshness_minutes", 120));
    }
}
