<?php

namespace App\Services\CFB\Predictions;

use App\Models\CalculationRelease;
use App\Services\CFB\Signals\CfbFrozenSignalEvidence;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;
use Carbon\CarbonImmutable;

class CfbCalculationReleaseRegistrar
{
    public function __construct(
        private readonly CfbCalculationReleaseDefinition $definition,
        private readonly CanonicalRulesReleaseRegistrar $registrar,
        private readonly CfbFrozenSignalEvidence $frozenEvidence,
    ) {}

    public function register(string $semanticVersion = CfbCalculationReleaseDefinition::SEMANTIC_VERSION, bool $approve = true, string $actor = 'artisan', string $reason = 'CFB canonical rules release registration.', ?CarbonImmutable $effectiveAt = null): CalculationRelease
    {
        // Reject an unusable pin before the shared registrar creates any draft.
        $this->frozenEvidence->resolve($this->definition->configuration(), CarbonImmutable::now());

        return $this->registrar->register($this->definition, CfbCalculator::class, CfbInputSnapshotBuilder::class, $semanticVersion, $approve, $actor, $reason, $effectiveAt);
    }
}
