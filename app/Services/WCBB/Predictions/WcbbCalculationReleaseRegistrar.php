<?php

namespace App\Services\WCBB\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\Basketball\CanonicalBasketballReleaseRegistrar;
use Carbon\CarbonImmutable;

class WcbbCalculationReleaseRegistrar
{
    public function __construct(
        private readonly WcbbCalculationReleaseDefinition $definition,
        private readonly CanonicalBasketballReleaseRegistrar $registrar,
    ) {}

    public function register(
        string $semanticVersion = WcbbCalculationReleaseDefinition::SEMANTIC_VERSION,
        bool $approve = true,
        string $actor = 'artisan',
        string $reason = 'WCBB canonical rules release registration.',
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        return $this->registrar->register(
            $this->definition,
            WcbbCalculator::class,
            WcbbInputSnapshotBuilder::class,
            $semanticVersion,
            $approve,
            $actor,
            $reason,
            $effectiveAt,
        );
    }
}
