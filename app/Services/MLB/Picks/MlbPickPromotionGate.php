<?php

namespace App\Services\MLB\Picks;

class MlbPickPromotionGate
{
    public function __construct(private readonly MlbPickValidationGate $validationGate) {}

    /**
     * @return array{status:string,recommendation_label:string,is_public:bool,is_tracking_only:bool,is_bet:bool,blocked_reasons:list<string>}
     */
    public function apply(string $internalLabel): array
    {
        if (! $this->validationGate->allowsPublicPromotion()) {
            return [
                'status' => 'tracking_only',
                'recommendation_label' => 'tracking_only',
                'is_public' => false,
                'is_tracking_only' => true,
                'is_bet' => false,
                'blocked_reasons' => $this->validationGate->blockedReasons(),
            ];
        }

        $isBet = $internalLabel === 'bet_candidate';

        return [
            'status' => $isBet ? 'promoted' : 'candidate',
            'recommendation_label' => $internalLabel,
            'is_public' => $isBet,
            'is_tracking_only' => ! $isBet,
            'is_bet' => $isBet,
            'blocked_reasons' => [],
        ];
    }
}
