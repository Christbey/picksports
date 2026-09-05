<?php

namespace App\Support\Betting;

use App\Enums\PredictionSport;

final readonly class UserBetPredictionReference
{
    public function __construct(
        public PredictionSport $sport,
        public int $predictionId,
        public ?int $sportEventId,
    ) {}

    /**
     * @return array{prediction_sport: string, prediction_id: int, prediction_type: string, sport_event_id: int|null}
     */
    public function persistenceAttributes(): array
    {
        return [
            'prediction_sport' => $this->sport->value,
            'prediction_id' => $this->predictionId,
            'prediction_type' => $this->sport->predictionModelClass(),
            'sport_event_id' => $this->sportEventId,
        ];
    }
}
