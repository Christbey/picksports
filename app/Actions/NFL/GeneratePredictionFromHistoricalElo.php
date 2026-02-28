<?php

namespace App\Actions\NFL;

use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;

class GeneratePredictionFromHistoricalElo
{
    public function execute(Game $game): string
    {
        if (! $game->homeTeam || ! $game->awayTeam) {
            return 'skipped';
        }

        $homeElo = $this->getEloAtDate($game->home_team_id, $game->game_date);
        $awayElo = $this->getEloAtDate($game->away_team_id, $game->game_date);

        $homeFieldAdvantage = config('nfl.elo.home_field_advantage');
        $adjustedHomeElo = $game->neutral_site ? $homeElo : $homeElo + $homeFieldAdvantage;

        $winProbability = $this->calculateWinProbability($adjustedHomeElo, $awayElo);

        $eloDiff = $adjustedHomeElo - $awayElo;
        $pointsPerElo = config('nfl.predictions.points_per_elo');
        $predictedSpread = $eloDiff * $pointsPerElo;
        $minSpread = config('nfl.predictions.min_spread');
        $maxSpread = config('nfl.predictions.max_spread');
        $predictedSpread = max($minSpread, min($maxSpread, $predictedSpread));

        $confidenceScore = abs($winProbability - 0.5) * 2;

        $averageTotal = config('nfl.predictions.average_total');
        $defaultElo = config('nfl.elo.default_rating');
        $combinedEloBonus = (($homeElo + $awayElo) - (2 * $defaultElo)) / 100;
        $predictedTotal = $averageTotal + $combinedEloBonus;

        $existing = Prediction::query()->where('game_id', $game->id)->first();

        Prediction::updateOrCreate(
            ['game_id' => $game->id],
            [
                'home_elo' => round($homeElo, 1),
                'away_elo' => round($awayElo, 1),
                'predicted_spread' => round($predictedSpread, 1),
                'predicted_total' => round($predictedTotal, 1),
                'win_probability' => round($winProbability, 3),
                'confidence_score' => round($confidenceScore, 2),
            ]
        );

        return $existing ? 'updated' : 'created';
    }

    protected function getEloAtDate(int $teamId, mixed $gameDate): float
    {
        $eloRecord = EloRating::query()
            ->where('team_id', $teamId)
            ->where('date', '<=', $gameDate)
            ->orderBy('date', 'desc')
            ->first();

        return $eloRecord ? (float) $eloRecord->elo_rating : config('nfl.elo.default_rating');
    }

    protected function calculateWinProbability(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }
}
