<?php

namespace App\Services\BettingRecommendations;

use App\Actions\NFL\CalculateBettingValue;
use App\Actions\Sports\CalculateBettingValue as GenericCalculateBettingValue;

class GameBettingRecommendationService
{
    /**
     * @var array<string, class-string>
     */
    private const SPORT_CALCULATORS = [
        'nfl' => CalculateBettingValue::class,
        'nba' => \App\Actions\NBA\CalculateBettingValue::class,
        'cbb' => \App\Actions\CBB\CalculateBettingValue::class,
        'wnba' => \App\Actions\WNBA\CalculateBettingValue::class,
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forGame(object $game, string $sport): array
    {
        $sport = strtolower($sport);
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
