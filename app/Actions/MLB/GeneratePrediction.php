<?php

namespace App\Actions\MLB;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected function getSport(): string
    {
        return 'mlb';
    }

    protected function getTeamMetricModel(): string
    {
        return TeamMetric::class;
    }

    protected function getPredictionModel(): string
    {
        return Prediction::class;
    }

    /**
     * Override execute to handle MLB-specific pitcher logic
     */
    public function execute(Model $game): ?Model
    {
        // Only generate predictions for scheduled games
        if ($game->status !== 'STATUS_SCHEDULED') {
            return null;
        }

        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return null;
        }

        // Get team Elo ratings
        $homeTeamElo = $homeTeam->elo_rating ?? config('mlb.elo.default_rating');
        $awayTeamElo = $awayTeam->elo_rating ?? config('mlb.elo.default_rating');

        // Get pitcher Elo with fallback logic
        $homePitcherResult = $this->getPitcherElo($homeTeam);
        $awayPitcherResult = $this->getPitcherElo($awayTeam);

        $homePitcherElo = $homePitcherResult['elo'];
        $awayPitcherElo = $awayPitcherResult['elo'];

        // Calculate combined Elo (60% team + 40% pitcher)
        $homeCombinedElo = ($homeTeamElo * config('mlb.elo.team_weight')) + ($homePitcherElo * config('mlb.elo.pitcher_weight'));
        $awayCombinedElo = ($awayTeamElo * config('mlb.elo.team_weight')) + ($awayPitcherElo * config('mlb.elo.pitcher_weight'));

        // Adjust for home field advantage
        $adjustedHomeElo = $homeCombinedElo + config('mlb.elo.home_field_advantage');

        // Calculate win probability
        $winProbability = $this->calculateWinProbability($adjustedHomeElo - $awayCombinedElo);

        // Calculate predicted spread
        $eloDiff = $adjustedHomeElo - $awayCombinedElo;
        $predictedSpread = $this->calculateSpread($eloDiff);

        // Calculate predicted total (runs)
        $predictedTotal = $this->calculateTotal($homeCombinedElo, $awayCombinedElo);

        // Calculate confidence score based on pitcher data availability
        $confidenceScore = $this->calculatePitcherConfidence(
            $homePitcherResult['confidence'],
            $awayPitcherResult['confidence']
        );

        // Create prediction with MLB-specific fields
        $predictionModel = $this->getPredictionModel();

        return $predictionModel::updateOrCreate(
            ['game_id' => $game->id],
            [
                'home_team_elo' => round($homeTeamElo, 1),
                'away_team_elo' => round($awayTeamElo, 1),
                'home_pitcher_elo' => round($homePitcherElo, 1),
                'away_pitcher_elo' => round($awayPitcherElo, 1),
                'home_combined_elo' => round($homeCombinedElo, 1),
                'away_combined_elo' => round($awayCombinedElo, 1),
                'predicted_spread' => round($predictedSpread, 1),
                'predicted_total' => round($predictedTotal, 1),
                'win_probability' => round($winProbability, 3),
                'confidence_score' => round($confidenceScore, 3),
            ]
        );
    }

    /**
     * Get pitcher Elo with three-tier fallback logic:
     * 1. Use known probable pitcher Elo (future enhancement - confidence 1.0)
     * 2. Use team's average pitcher Elo from last 10 starts (confidence 0.75)
     * 3. Use league average 1500 (confidence 0.5)
     */
    protected function getPitcherElo(Team $team): array
    {
        // TODO: When ESPN provides probable pitcher data, check for it first
        // For now, fall back to team's recent pitcher average

        // Get team's recent pitcher Elo ratings
        $recentPitcherElos = PitcherEloRating::query()
            ->whereHas('player', function ($query) use ($team) {
                $query->where('team_id', $team->id);
            })
            ->orderByDesc('date')
            ->limit(config('mlb.elo.recent_starts_limit'))
            ->pluck('elo_rating');

        if ($recentPitcherElos->isNotEmpty()) {
            // Tier 2: Use team's average pitcher Elo from recent starts
            return [
                'elo' => $recentPitcherElos->avg(),
                'confidence' => 0.75,
            ];
        }

        // Tier 3: Use league average (no pitcher data available)
        return [
            'elo' => config('mlb.elo.default_rating'),
            'confidence' => 0.5,
        ];
    }

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Not used in MLB - we override execute() instead
        return 0.0;
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Not used in MLB - we override execute() instead
        return 0.0;
    }

    protected function calculateSpread(float $eloDiff): float
    {
        // Convert Elo difference to runs (approximately 25 Elo points = 0.5 run)
        // Positive spread means home team is favored
        return $eloDiff / 50;
    }

    protected function calculateTotal(float $homeElo, float $awayElo): float
    {
        // Base total on average runs per game, adjusted by combined team strength
        // Higher Elo teams tend to score more runs
        $avgElo = ($homeElo + $awayElo) / 2;
        $eloAdjustment = ($avgElo - config('mlb.elo.default_rating')) / 100;

        return config('mlb.elo.average_runs_per_game') + $eloAdjustment;
    }

    protected function calculatePitcherConfidence(float $homeConfidence, float $awayConfidence): float
    {
        // Average the confidence scores from both pitchers
        return ($homeConfidence + $awayConfidence) / 2;
    }

    public function executeForAllScheduledGames(int $season): int
    {
        $games = \App\Models\MLB\Game::query()
            ->where('season', $season)
            ->where('status', 'STATUS_SCHEDULED')
            ->with(['homeTeam', 'awayTeam'])
            ->get();

        $generated = 0;

        foreach ($games as $game) {
            $prediction = $this->execute($game);
            if ($prediction) {
                $generated++;
            }
        }

        return $generated;
    }
}
