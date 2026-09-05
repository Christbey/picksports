<?php

namespace App\Services\NBA\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\Basketball\CanonicalBasketballReleaseRegistrar;
use Carbon\CarbonImmutable;

class NbaCalculationReleaseRegistrar
{
    public function __construct(
        private readonly NbaCalculationReleaseDefinition $definition,
        private readonly CanonicalBasketballReleaseRegistrar $registrar,
    ) {}

    public function register(
        string $semanticVersion = NbaCalculationReleaseDefinition::SEMANTIC_VERSION,
        bool $approve = true,
        string $actor = 'artisan',
        string $reason = 'NBA canonical rules release registration.',
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        return $this->registrar->register(
            $this->definition,
            NbaCalculator::class,
            NbaInputSnapshotBuilder::class,
            $semanticVersion,
            $approve,
            $actor,
            $reason,
            $effectiveAt,
        );
    }
}
