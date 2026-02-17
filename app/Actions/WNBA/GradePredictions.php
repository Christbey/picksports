<?php

namespace App\Actions\WNBA;

use App\Models\WNBA\Prediction;
use Illuminate\Support\Collection;

class GradePredictions
{
    public function execute(?int $season = null): array
    {
        $query = Prediction::query()
            ->join('wnba_games', 'wnba_predictions.game_id', '=', 'wnba_games.id')
            ->where('wnba_games.status', 'STATUS_FINAL')
            ->whereNotNull('wnba_games.home_score')
            ->whereNotNull('wnba_games.away_score')
            ->whereNull('wnba_predictions.graded_at')
            ->select('wnba_predictions.*', 'wnba_games.home_score', 'wnba_games.away_score');

        if ($season) {
            $query->where('wnba_games.season', $season);
        }

        $predictions = $query->get();

        if ($predictions->isEmpty()) {
            return [
                'graded' => 0,
                'total_games' => 0,
                'winner_accuracy' => 0,
                'avg_spread_error' => 0,
                'avg_total_error' => 0,
            ];
        }

        $graded = 0;
        $winnerCorrect = 0;
        $spreadErrors = [];
        $totalErrors = [];

        foreach ($predictions as $prediction) {
            $actualSpread = $prediction->home_score - $prediction->away_score;
            $actualTotal = $prediction->home_score + $prediction->away_score;

            $spreadError = abs($actualSpread - $prediction->predicted_spread);
            $totalError = abs($actualTotal - $prediction->predicted_total);

            // Check if winner was predicted correctly
            $isWinnerCorrect = ($actualSpread > 0 && $prediction->predicted_spread > 0)
                || ($actualSpread < 0 && $prediction->predicted_spread < 0);

            // Update prediction with results
            $prediction->update([
                'actual_spread' => round($actualSpread, 1),
                'actual_total' => round($actualTotal, 1),
                'spread_error' => round($spreadError, 1),
                'total_error' => round($totalError, 1),
                'winner_correct' => $isWinnerCorrect,
                'graded_at' => now(),
            ]);

            if ($isWinnerCorrect) {
                $winnerCorrect++;
            }

            $spreadErrors[] = $spreadError;
            $totalErrors[] = $totalError;
            $graded++;
        }

        return [
            'graded' => $graded,
            'total_games' => $graded,
            'winner_accuracy' => $graded > 0 ? round(($winnerCorrect / $graded) * 100, 1) : 0,
            'avg_spread_error' => $graded > 0 ? round(array_sum($spreadErrors) / count($spreadErrors), 2) : 0,
            'avg_total_error' => $graded > 0 ? round(array_sum($totalErrors) / count($totalErrors), 2) : 0,
        ];
    }

    public function getStatsByConfidence(?int $season = null): Collection
    {
        $query = Prediction::query()
            ->whereNotNull('graded_at');

        if ($season) {
            $query->join('wnba_games', 'wnba_predictions.game_id', '=', 'wnba_games.id')
                ->where('wnba_games.season', $season)
                ->select('wnba_predictions.*');
        }

        return $query->get()->groupBy('confidence_score')->map(function ($predictions, $confidence) {
            $total = $predictions->count();
            $winnerCorrect = $predictions->where('winner_correct', true)->count();
            $avgSpreadError = $predictions->avg('spread_error');
            $avgTotalError = $predictions->avg('total_error');

            return [
                'confidence' => $confidence,
                'total_games' => $total,
                'winner_accuracy' => $total > 0 ? round(($winnerCorrect / $total) * 100, 1) : 0,
                'avg_spread_error' => round($avgSpreadError, 2),
                'avg_total_error' => round($avgTotalError, 2),
            ];
        })->sortByDesc('confidence')->values();
    }
}
