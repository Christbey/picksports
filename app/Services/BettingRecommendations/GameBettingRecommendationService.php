<?php

namespace App\Services\BettingRecommendations;

use App\Actions\NFL\CalculateBettingValue;
use App\Actions\Sports\CalculateBettingValue as GenericCalculateBettingValue;
use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Services\Predictions\PredictionDeliverySource;
use Illuminate\Database\Eloquent\Model;

class GameBettingRecommendationService
{
    public function __construct(
        private readonly PredictionDeliverySource $predictions,
        private readonly CanonicalBettingRecommendations $canonical,
    ) {}

    /**
     * @var array<string, class-string>
     */
    private const SPORT_CALCULATORS = [
        'nfl' => CalculateBettingValue::class,
        'nba' => \App\Actions\NBA\CalculateBettingValue::class,
        'cbb' => \App\Actions\CBB\CalculateBettingValue::class,
        'wnba' => \App\Actions\WNBA\CalculateBettingValue::class,
    ];

    /** @return list<array<string, mixed>> */
    public function forPrediction(PredictionDeliveryContext $prediction): array
    {
        if (! $this->predictions->isSelected($prediction)) {
            return [];
        }

        return $prediction->isCanonical()
            ? $this->canonical->forPrediction($prediction)
            : $this->forGame($prediction->game, $prediction->sport);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forGame(object $game, string $sport): array
    {
        $sport = strtolower($sport);
        if ($this->predictions->usesCanonical($sport)) {
            $prediction = $game instanceof Model ? $this->predictions->forGame($game, $sport) : null;

            return $prediction !== null ? $this->canonical->forPrediction($prediction) : [];
        }

        $calculatorClass = self::SPORT_CALCULATORS[$sport] ?? GenericCalculateBettingValue::class;
        $calculator = app($calculatorClass);

        $recommendations = $calculatorClass === GenericCalculateBettingValue::class
            ? $calculator->execute($game, $sport)
            : $calculator->execute($game);

        if (! is_array($recommendations)) {
            return [];
        }

        return array_values($recommendations);
    }
}
