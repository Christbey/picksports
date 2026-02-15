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

        // Calculate confidence score based on data quality
        $confidenceScore = $this->calculateConfidence($homeMetrics, $awayMetrics, $homeElo, $awayElo);

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
     * Calculate confidence score based on data quality
     */
    protected function calculateConfidence(?Model $homeMetrics, ?Model $awayMetrics, int $homeElo, int $awayElo): float
    {
        $sport = $this->getSport();
        $confidenceConfig = config("{$sport}.prediction.confidence");
        $defaultElo = config("{$sport}.elo.default") ?? config("{$sport}.elo.default_rating");
        $confidence = 0;

        // Base confidence from having Elo data
        $confidence += $confidenceConfig['base'] ?? 40;

        // Bonus for having team metrics
        if ($homeMetrics) {
            $confidence += $confidenceConfig['home_metrics'] ?? 15;
        }

        if ($awayMetrics) {
            $confidence += $confidenceConfig['away_metrics'] ?? 15;
        }

        // Bonus for non-default Elo ratings (teams have played games)
        if ($homeElo !== $defaultElo) {
            $confidence += $confidenceConfig['home_non_default_elo'] ?? 15;
        }

        if ($awayElo !== $defaultElo) {
            $confidence += $confidenceConfig['away_non_default_elo'] ?? 15;
        }

        return round(min($confidence, 100), 2);
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
