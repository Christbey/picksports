<?php

namespace App\Services\Api\V2;

final readonly class CanonicalPredictionPresentationData
{
    /** @param array<string, mixed> $confidenceContext
     * @param  array<string, mixed>|null  $valueSignal
     */
    public function __construct(public array $confidenceContext, public ?array $valueSignal) {}
}
