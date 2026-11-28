<?php

namespace App\Services\WNBA\Predictions;

use App\Models\CalculationRelease;
use App\Services\Predictions\Basketball\NeutralAwareBasketballCandidateRegistrar;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;
use Carbon\CarbonImmutable;

class WnbaCalculationReleaseRegistrar
{
    public function __construct(
        private readonly WnbaCalculationReleaseDefinition $definition,
        private readonly CanonicalRulesReleaseRegistrar $registrar,
    ) {}

    public function register(
        string $semanticVersion = WnbaCalculationReleaseDefinition::SEMANTIC_VERSION,
        bool $approve = true,
        string $actor = 'artisan',
        string $reason = 'WNBA canonical rules release registration.',
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        return $this->registrar->register(
            $semanticVersion === WnbaFullModelReleaseDefinition::SEMANTIC_VERSION
                ? new WnbaFullModelReleaseDefinition : $this->definition,
            WnbaCalculator::class,
            WnbaInputSnapshotBuilder::class,
            $semanticVersion,
            $approve,
            $actor,
            $reason,
            $effectiveAt,
        );
    }

    public function registerNeutralAwareDraft(string $actor = 'artisan', string $reason = 'Opt-in neutral-aware draft candidate.'): CalculationRelease
    {
        return app(NeutralAwareBasketballCandidateRegistrar::class)->registerDraft(
            $this->definition, WnbaCalculator::class, $actor, $reason,
        );
    }
}
