<?php

namespace App\Services\WNBA\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\Basketball\CanonicalBasketballReleaseRegistrar;
use Carbon\CarbonImmutable;

class WnbaCalculationReleaseRegistrar
{
    public function __construct(
        private readonly WnbaCalculationReleaseDefinition $definition,
        private readonly CanonicalBasketballReleaseRegistrar $registrar,
    ) {}

    public function register(
        string $semanticVersion = WnbaCalculationReleaseDefinition::SEMANTIC_VERSION,
        bool $approve = true,
        string $actor = 'artisan',
        string $reason = 'WNBA canonical rules release registration.',
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        return $this->registrar->register(
            $this->definition,
            WnbaCalculator::class,
            WnbaInputSnapshotBuilder::class,
            $semanticVersion,
            $approve,
            $actor,
            $reason,
            $effectiveAt,
        );
    }
}
