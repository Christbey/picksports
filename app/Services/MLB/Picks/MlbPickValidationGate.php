<?php

namespace App\Services\MLB\Picks;

class MlbPickValidationGate
{
    /**
     * @return list<string>
     */
    public function blockedReasons(?string $marketType = null): array
    {
        if (! (bool) config('mlb.picks.public_promotion_enabled', false)) {
            return ['mlb_public_promotion_unvalidated'];
        }

        $promotionKey = $this->promotionKey($marketType);
        if ($promotionKey !== null && ! (bool) config("mlb.picks.market_promotion.{$promotionKey}", false)) {
            return ["mlb_{$promotionKey}_promotion_unvalidated"];
        }

        return [];
    }

    public function allowsPublicPromotion(?string $marketType = null): bool
    {
        return $this->blockedReasons($marketType) === [];
    }

    private function promotionKey(?string $marketType): ?string
    {
        if ($marketType === null || $marketType === '') {
            return null;
        }

        return match (true) {
            str_starts_with($marketType, 'first_inning') => 'first_inning',
            str_starts_with($marketType, 'first_3') => 'first_3',
            str_starts_with($marketType, 'first_5') => 'first_5',
            $marketType === 'run_line' => 'run_line',
            $marketType === 'total' => 'total',
            $marketType === 'player_prop' => 'props',
            default => 'moneyline',
        };
    }
}
