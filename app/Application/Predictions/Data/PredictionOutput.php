<?php

namespace App\Application\Predictions\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PredictionOutput
{
    /**
     * @param  list<PredictionMarketOutput>  $markets
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        public array $markets,
        public array $metadata = [],
        public array $diagnostics = [],
        public ?CarbonImmutable $generatedAt = null,
    ) {
        if ($this->markets === []) {
            throw new InvalidArgumentException('A prediction output must contain at least one market.');
        }

        foreach ($this->markets as $market) {
            if (! $market instanceof PredictionMarketOutput) {
                throw new InvalidArgumentException('Prediction outputs must contain typed market outputs.');
            }
        }

        $keys = array_map(
            fn (PredictionMarketOutput $market): string => $market->marketType.':'.$market->selection,
            $this->markets,
        );

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('Prediction output markets must be unique by type and selection.');
        }
    }

    /** @return array{markets: list<array<string, float|string|null>>, metadata: array<string, mixed>} */
    public function hashablePayload(): array
    {
        $markets = array_map(
            fn (PredictionMarketOutput $market): array => $market->toArray(),
            $this->markets,
        );

        usort($markets, fn (array $left, array $right): int => [
            $left['market_type'],
            $left['selection'],
        ] <=> [
            $right['market_type'],
            $right['selection'],
        ]);

        return [
            'markets' => $markets,
            'metadata' => $this->metadata,
        ];
    }
}
