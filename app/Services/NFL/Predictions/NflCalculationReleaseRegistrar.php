<?php

namespace App\Services\NFL\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;
use Carbon\CarbonImmutable;

class NflCalculationReleaseRegistrar
{
    public function __construct(private readonly NflCalculationReleaseDefinition $definition, private readonly CanonicalRulesReleaseRegistrar $registrar) {}

    public function register(string $semanticVersion = NflCalculationReleaseDefinition::SEMANTIC_VERSION, bool $approve = true, string $actor = 'artisan', string $reason = 'NFL canonical rules release registration.', ?CarbonImmutable $effectiveAt = null): CalculationRelease
    {
        return $this->registrar->register($this->definition, NflCalculator::class, NflInputSnapshotBuilder::class, $semanticVersion, $approve, $actor, $reason, $effectiveAt);
    }
}
