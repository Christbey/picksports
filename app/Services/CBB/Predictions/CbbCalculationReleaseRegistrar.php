<?php

namespace App\Services\CBB\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;
use Carbon\CarbonImmutable;

class CbbCalculationReleaseRegistrar
{
    public function __construct(
        private readonly CbbCalculationReleaseDefinition $definition,
        private readonly CanonicalRulesReleaseRegistrar $registrar,
    ) {}

    public function register(
        string $semanticVersion = CbbCalculationReleaseDefinition::SEMANTIC_VERSION,
        bool $approve = true,
        string $actor = 'artisan',
        string $reason = 'CBB canonical rules release registration.',
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        return $this->registrar->register(
            match ($semanticVersion) {
                '1.2.0', CbbScoreMarginReleaseDefinition::SEMANTIC_VERSION => new CbbScoreMarginReleaseDefinition,
                CbbFullModelReleaseDefinition::SEMANTIC_VERSION => new CbbFullModelReleaseDefinition,
                default => $this->definition,
            },
            CbbCalculator::class,
            CbbInputSnapshotBuilder::class,
            $semanticVersion,
            $approve,
            $actor,
            $reason,
            $effectiveAt,
        );
    }
}
