<?php

namespace App\Services\Predictions\Basketball;

use App\Contracts\Predictions\BasketballReleaseDefinition;

final class NeutralAwareBasketballReleaseDefinition implements BasketballReleaseDefinition
{
    public function __construct(private readonly BasketballReleaseDefinition $base) {}

    public function sport(): string
    {
        return $this->base->sport();
    }

    public function calculatorName(): string
    {
        return $this->base->calculatorName().'-neutral-candidate';
    }

    public function semanticVersion(): string
    {
        return '1.1.0';
    }

    public function inputSchemaVersion(): string
    {
        return $this->sport().'-pregame-neutral-v2';
    }

    public function configuration(): array
    {
        $configuration = $this->base->configuration();
        $configuration['elo']['respect_neutral_site'] = true;
        $configuration['neutral_context_policy'] = [
            'version' => 'observed-neutral-v2',
            'unknown_fallback' => 'legacy_home_context',
            'historical_availability_verified' => false,
        ];

        return $configuration;
    }
}
