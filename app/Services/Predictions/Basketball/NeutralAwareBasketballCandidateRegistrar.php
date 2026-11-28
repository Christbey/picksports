<?php

namespace App\Services\Predictions\Basketball;

use App\Contracts\Predictions\BasketballReleaseDefinition;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;

final class NeutralAwareBasketballCandidateRegistrar
{
    public function __construct(private readonly CanonicalRulesReleaseRegistrar $registrar) {}

    public function registerDraft(BasketballReleaseDefinition $base, string $calculator, string $actor, string $reason): CalculationRelease
    {
        $definition = new NeutralAwareBasketballReleaseDefinition($base);
        $existing = CalculationRelease::query()->where('sport', $base->sport())->where('phase', 'pregame')
            ->where('calculator_name', $definition->calculatorName())->where('semantic_version', '1.1.0')->first();
        if ($existing !== null) {
            $this->assertInactiveDraft($existing);
        }
        $release = $this->registrar->register($definition, $calculator, NeutralAwareBasketballInputSnapshotBuilder::class,
            '1.1.0', false, $actor, $reason, null);
        $this->assertInactiveDraft($release->fresh());

        return $release;
    }

    private function assertInactiveDraft(CalculationRelease $release): void
    {
        if ($release->status !== 'draft') {
            throw new PredictionLifecycleException('Neutral-aware 1.1.0 already exists in an immutable non-draft state.');
        }
        foreach (['effective_at', 'approved_at', 'retired_at', 'invalidated_at'] as $timestamp) {
            if ($release->getAttribute($timestamp) !== null) {
                throw new PredictionLifecycleException('Neutral-aware draft has contradictory lifecycle timestamps; no changes performed.');
            }
        }
    }
}
