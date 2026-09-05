<?php

namespace App\Services\MLB\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;
use Carbon\CarbonImmutable;

class MlbCalculationReleaseRegistrar
{
    public function __construct(private readonly MlbCalculationReleaseDefinition $definition, private readonly CanonicalRulesReleaseRegistrar $registrar) {}

    public function register(string $semanticVersion = MlbCalculationReleaseDefinition::SEMANTIC_VERSION, bool $approve = true, string $actor = 'artisan', string $reason = 'MLB canonical rules release registration.', ?CarbonImmutable $effectiveAt = null): CalculationRelease
    {
        return $this->registrar->register($this->definition, MlbCalculator::class, MlbInputSnapshotBuilder::class, $semanticVersion, $approve, $actor, $reason, $effectiveAt);
    }
}
