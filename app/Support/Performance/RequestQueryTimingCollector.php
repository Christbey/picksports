<?php

namespace App\Support\Performance;

final class RequestQueryTimingCollector
{
    /** @var list<object{count: int, milliseconds: float}> */
    private array $measurements = [];

    public function begin(): object
    {
        $measurement = (object) ['count' => 0, 'milliseconds' => 0.0];
        $this->measurements[] = $measurement;

        return $measurement;
    }

    public function record(float $milliseconds): void
    {
        foreach ($this->measurements as $measurement) {
            $measurement->count++;
            $measurement->milliseconds += $milliseconds;
        }
    }

    public function finish(object $measurement): void
    {
        $this->measurements = array_values(array_filter(
            $this->measurements,
            static fn (object $active): bool => $active !== $measurement,
        ));
    }
}
