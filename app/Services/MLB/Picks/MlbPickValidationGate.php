<?php

namespace App\Services\MLB\Picks;

class MlbPickValidationGate
{
    /**
     * @return list<string>
     */
    public function blockedReasons(): array
    {
        if (! (bool) config('mlb.picks.public_promotion_enabled', false)) {
            return ['mlb_public_promotion_unvalidated'];
        }

        return [];
    }

    public function allowsPublicPromotion(): bool
    {
        return $this->blockedReasons() === [];
    }
}
