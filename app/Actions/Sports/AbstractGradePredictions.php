<?php

namespace App\Actions\Sports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Model::class;

    protected const PREDICTION_TABLE = '';

    protected const GAMES_TABLE = '';

    public function execute(?int $season = null): array
    {
        $query = $this->newPredictionQuery()
            ->join($this->gamesTable(), $this->predictionTable().'.game_id', '=', $this->gamesTable().'.id')
            ->where($this->gamesTable().'.status', 'STATUS_FINAL')
            ->whereNotNull($this->gamesTable().'.home_score')
            ->whereNotNull($this->gamesTable().'.away_score')
            ->whereNull($this->predictionTable().'.graded_at')
            ->select($this->predictionTable().'.*', $this->gamesTable().'.home_score', $this->gamesTable().'.away_score');

        if ($season !== null) {
            $query->where($this->gamesTable().'.season', $season);
        }

        $predictions = $query->get();

        if ($predictions->isEmpty()) {
            return $this->emptyResults();
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

            $isWinnerCorrect = ($actualSpread > 0 && $prediction->predicted_spread > 0)
                || ($actualSpread < 0 && $prediction->predicted_spread < 0);

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
            'winner_accuracy' => round(($winnerCorrect / $graded) * 100, 1),
            'avg_spread_error' => round(array_sum($spreadErrors) / count($spreadErrors), 2),
            'avg_total_error' => round(array_sum($totalErrors) / count($totalErrors), 2),
        ];
    }

    public function getStatsByConfidence(?int $season = null): Collection
    {
        $query = $this->newPredictionQuery()->whereNotNull('graded_at');

        if ($season !== null) {
            $query->join($this->gamesTable(), $this->predictionTable().'.game_id', '=', $this->gamesTable().'.id')
                ->where($this->gamesTable().'.season', $season)
                ->select($this->predictionTable().'.*');
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

    protected function predictionTable(): string
    {
        if (static::PREDICTION_TABLE === '') {
            throw new \RuntimeException('PREDICTION_TABLE must be defined on grade predictions action.');
        }

        return static::PREDICTION_TABLE;
    }

    protected function gamesTable(): string
    {
        if (static::GAMES_TABLE === '') {
            throw new \RuntimeException('GAMES_TABLE must be defined on grade predictions action.');
        }

        return static::GAMES_TABLE;
    }

    protected function newPredictionQuery()
    {
        $predictionModel = static::PREDICTION_MODEL;
        if ($predictionModel === Model::class) {
            throw new \RuntimeException('PREDICTION_MODEL must be defined on grade predictions action.');
        }

        return $predictionModel::query();
    }

    private function emptyResults(): array
    {
        return [
            'graded' => 0,
            'total_games' => 0,
            'winner_accuracy' => 0,
            'avg_spread_error' => 0,
            'avg_total_error' => 0,
        ];
    }
}
