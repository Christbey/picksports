<?php

namespace App\Services\CFB\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;
use Carbon\CarbonImmutable;

class CfbCalculationReleaseRegistrar
{
    public function __construct(private readonly CfbCalculationReleaseDefinition $definition, private readonly CanonicalRulesReleaseRegistrar $registrar) {}

    public function register(string $semanticVersion = CfbCalculationReleaseDefinition::SEMANTIC_VERSION, bool $approve = true, string $actor = 'artisan', string $reason = 'CFB canonical rules release registration.', ?CarbonImmutable $effectiveAt = null): CalculationRelease
    {
        return $this->registrar->register($this->definition, CfbCalculator::class, CfbInputSnapshotBuilder::class, $semanticVersion, $approve, $actor, $reason, $effectiveAt);
    }
}
