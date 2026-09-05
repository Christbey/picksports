<?php

namespace App\Services\CBB\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\Basketball\CanonicalBasketballReleaseRegistrar;
use Carbon\CarbonImmutable;

class CbbCalculationReleaseRegistrar
{
    public function __construct(
        private readonly CbbCalculationReleaseDefinition $definition,
        private readonly CanonicalBasketballReleaseRegistrar $registrar,
    ) {}

    public function register(
        string $semanticVersion = CbbCalculationReleaseDefinition::SEMANTIC_VERSION,
        bool $approve = true,
        string $actor = 'artisan',
        string $reason = 'CBB canonical rules release registration.',
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        return $this->registrar->register(
            $this->definition,
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
