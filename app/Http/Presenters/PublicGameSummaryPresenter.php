<?php

namespace App\Http\Presenters;

use App\Application\Sports\ReadModels\GameSummary;

final class PublicGameSummaryPresenter
{
    /** @return array<string, mixed> */
    public function featuredPrediction(GameSummary $game): array
    {
        $prediction = $game->prediction;
        $homeMoneyline = $prediction?->market('moneyline', 'home');
        $spread = $prediction?->market('spread', 'home');
        $total = $prediction?->market('total', 'combined');
        $homeName = $game->homeTeam?->displayName ?? 'Unknown Team';
        $awayName = $game->awayTeam?->displayName ?? 'Unknown Team';
        $homeWinProbability = $homeMoneyline?->probability ?? $prediction?->homeWinProbability ?? 0.5;

        return [
            'id' => $prediction?->id,
            'game_id' => $game->id,
            'matchup' => trim($awayName.' at '.$homeName),
            'game_date' => $game->gameDate,
            'status' => $game->status,
            'pick' => $homeWinProbability >= 0.5 ? $homeName : $awayName,
            'home_team_abbreviation' => $game->homeTeam?->abbreviation,
            'away_team_abbreviation' => $game->awayTeam?->abbreviation,
            'predicted_spread' => $spread?->projectedLine ?? $prediction?->predictedSpread,
            'predicted_total' => $total?->projectedLine ?? $prediction?->predictedTotal,
            'confidence_score' => $prediction?->confidenceScore,
            'home_win_probability' => round($homeWinProbability * 100, 1),
        ];
    }
}
