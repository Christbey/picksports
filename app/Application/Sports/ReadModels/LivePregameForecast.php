<?php

namespace App\Application\Sports\ReadModels;

final readonly class LivePregameForecast
{
    public function __construct(
        public ?float $predicted_spread,
        public ?float $predicted_total,
        public ?float $win_probability,
        public array $lineage,
    ) {}

    public function toArray(): array
    {
        return [...$this->lineage, 'predicted_spread' => $this->predicted_spread,
            'predicted_total' => $this->predicted_total, 'win_probability' => $this->win_probability];
    }

    public static function fromArray(array $baseline): self
    {
        return new self($baseline['predicted_spread'] ?? null, $baseline['predicted_total'] ?? null,
            $baseline['win_probability'] ?? null, $baseline);
    }
}
