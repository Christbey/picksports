<?php

namespace App\Actions\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractPredictionGenerator
{
    /**
     * Get the sport identifier for config lookups
     */
    abstract protected function getSport(): string;

    /**
     * Get the TeamMetric model class
     */
    abstract protected function getTeamMetricModel(): string;

    /**
     * Get the Prediction model class
     */
    abstract protected function getPredictionModel(): string;

    /**
     * Calculate sport-specific predicted spread
     */
    abstract protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float;

    /**
     * Calculate sport-specific predicted total
     */
    abstract protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float;

    /**
     * Execute prediction generation for a game
     */
    public function execute(Model $game): ?Model
    {
        // Don't predict games that are already completed
        if ($game->status === 'STATUS_FINAL') {
            return null;
        }

        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return null;
        }

        // Get current Elo ratings
        $sport = $this->getSport();
        $defaultElo = config("{$sport}.elo.default") ?? config("{$sport}.elo.default_rating");
        $homeElo = $homeTeam->elo_rating ?? $defaultElo;
        $awayElo = $awayTeam->elo_rating ?? $defaultElo;

        // Get team metrics for the season
        $teamMetricModel = $this->getTeamMetricModel();

        $homeMetrics = $teamMetricModel::query()
            ->where('team_id', $homeTeam->id)
            ->where('season', $game->season)
            ->first();

        $awayMetrics = $teamMetricModel::query()
            ->where('team_id', $awayTeam->id)
            ->where('season', $game->season)
            ->first();

        // Calculate predictions using sport-specific logic
        $predictedSpread = $this->calculatePredictedSpread($homeElo, $awayElo, $homeMetrics, $awayMetrics, $game);
        $predictedTotal = $this->calculatePredictedTotal($homeMetrics, $awayMetrics, $game);

        // Calculate win probability from spread
        $winProbability = $this->calculateWinProbability($predictedSpread);

        // Calculate confidence score based on win probability
        $confidenceScore = $this->calculateConfidence($winProbability);

        // Build prediction data
        $predictionData = $this->buildPredictionData(
            $homeElo,
            $awayElo,
            $homeMetrics,
            $awayMetrics,
            $predictedSpread,
            $predictedTotal,
            $winProbability,
            $confidenceScore
        );

        // Create or update prediction
        $predictionModel = $this->getPredictionModel();

        return $predictionModel::updateOrCreate(
            ['game_id' => $game->id],
            $predictionData
        );
    }

    /**
     * Calculate win probability from spread using logistic function
     */
    protected function calculateWinProbability(float $spread): float
    {
        $sport = $this->getSport();
        $coefficient = config("{$sport}.prediction.spread_to_probability_coefficient") ?? 7.0;
        $probability = 1 / (1 + exp(-$spread / $coefficient));

        return round($probability, 3);
    }

    /**
     * Calculate confidence score from win probability.
     *
     * Maps the predicted winner's probability to a 50-100 scale:
     * 95% WP → 95 confidence, 55% WP → 55 confidence, 30% WP (away favored) → 70 confidence
     */
    protected function calculateConfidence(float $winProbability): float
    {
        return round(max($winProbability, 1 - $winProbability) * 100, 2);
    }

    /**
     * Build prediction data array (can be overridden for sport-specific fields)
     */
    protected function buildPredictionData(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        float $predictedSpread,
        float $predictedTotal,
        float $winProbability,
        float $confidenceScore
    ): array {
        return [
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
        ];
    }
}
