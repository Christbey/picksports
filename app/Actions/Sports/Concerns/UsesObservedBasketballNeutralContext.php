<?php

namespace App\Actions\Sports\Concerns;

use Illuminate\Database\Eloquent\Model;

trait UsesObservedBasketballNeutralContext
{
    /** Current observations do not establish that venue facts existed before a historical kickoff. */
    private function observedNeutralContext(?Model $game, string $sport, bool $historical = false): array
    {
        $observed = $historical ? null : $game?->getAttribute('neutral_site');
        $observed = is_bool($observed) ? $observed : null;

        return [
            'policy_version' => 'observed-neutral-v2',
            'observed_neutral_site' => $observed,
            'effective_home_court_advantage' => $observed === true ? 0.0 : (float) config("$sport.elo.home_court_advantage"),
            'unknown_fallback_applied' => $observed === null,
            'unknown_fallback' => 'legacy_home_context',
            'risk_flags' => $observed === null ? ['neutral_site_unknown'] : [],
            'historical_availability_verified' => false,
            'historical_reconstruction' => $historical,
            'source' => 'legacy_game.neutral_site',
            'source_observed_at' => null,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
