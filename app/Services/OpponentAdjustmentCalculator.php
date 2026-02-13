<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Calculates opponent-adjusted metrics for college basketball teams.
 *
 * This service implements an iterative algorithm that adjusts team offensive/defensive
 * efficiency ratings based on the strength of their opponents. The algorithm converges
 * when changes between iterations fall below a threshold.
 *
 * Based on Ken Pomeroy's adjusted efficiency methodology.
 */
class OpponentAdjustmentCalculator
{
    private string $sport;

    private int $season;

    private int $maxIterations;

    private float $convergenceThreshold;

    private float $dampingFactor;

    private float $normalizationBaseline;

    private $estimatePossessions;

    public function __construct(string $sport, int $season, callable $estimatePossessions)
    {
        $this->sport = strtolower($sport);
        $this->season = $season;
        $this->estimatePossessions = $estimatePossessions;

        // Load config values
        $this->maxIterations = config("{$this->sport}.metrics.max_adjustment_iterations", 10);
        $this->convergenceThreshold = config("{$this->sport}.metrics.adjustment_convergence_threshold", 0.1);
        $this->dampingFactor = config("{$this->sport}.metrics.adjustment_damping_factor", 0.5);
        $this->normalizationBaseline = config("{$this->sport}.normalization_baseline", 100.0);
    }

    /**
     * Calculate opponent-adjusted metrics for all teams.
     *
     * @param  Collection  $metrics  Collection of TeamMetric models
     * @param  Collection  $games  Collection of Game models
     */
    public function calculate(Collection $metrics, Collection $games): void
    {
        if ($metrics->isEmpty()) {
            Log::info('No metrics to adjust - skipping opponent adjustment', [
                'sport' => $this->sport,
                'season' => $this->season,
            ]);

            return;
        }

        Log::info('Starting opponent adjustment', [
            'sport' => $this->sport,
            'season' => $this->season,
            'teams_count' => $metrics->count(),
            'games_count' => $games->count(),
            'max_iterations' => $this->maxIterations,
        ]);

        $currentMetrics = $this->initializeMetrics($metrics);
        $convergenceData = $this->runIterativeAdjustment($currentMetrics, $games, $metrics);
        $this->normalizeToBaseline($currentMetrics);
        $this->saveAdjustedMetrics($currentMetrics, $metrics);

        Log::info('Opponent adjustment complete', array_merge([
            'sport' => $this->sport,
            'season' => $this->season,
        ], $convergenceData));
    }

    /**
     * Initialize metrics arrays for iteration.
     */
    private function initializeMetrics(Collection $metrics): array
    {
        $currentOffense = [];
        $currentDefense = [];
        $currentTempo = [];

        foreach ($metrics as $metric) {
            $currentOffense[$metric->team_id] = (float) $metric->offensive_efficiency;
            $currentDefense[$metric->team_id] = (float) $metric->defensive_efficiency;
            $currentTempo[$metric->team_id] = (float) $metric->tempo;
        }

        return compact('currentOffense', 'currentDefense', 'currentTempo');
    }

    /**
     * Run iterative adjustment until convergence.
     */
    private function runIterativeAdjustment(array &$currentMetrics, Collection $games, Collection $metrics): array
    {
        $converged = false;
        $iterationCount = 0;
        $maxChange = 0;

        while (! $converged && $iterationCount < $this->maxIterations) {
            $iterationCount++;

            // Defensive check - should never happen due to earlier isEmpty check
            if (empty($currentMetrics['currentDefense']) || empty($currentMetrics['currentOffense']) || empty($currentMetrics['currentTempo'])) {
                Log::warning('Empty metrics array during opponent adjustment', [
                    'sport' => $this->sport,
                    'season' => $this->season,
                    'iteration' => $iterationCount,
                ]);
                break;
            }

            $newMetrics = $this->performIteration($currentMetrics, $games, $metrics);
            $maxChange = $this->calculateMaxChange($currentMetrics, $newMetrics);

            $currentMetrics = $newMetrics;
            $converged = $maxChange < $this->convergenceThreshold;

            Log::debug('Opponent adjustment iteration complete', [
                'sport' => $this->sport,
                'season' => $this->season,
                'iteration' => $iterationCount,
                'max_change' => round($maxChange, 4),
                'converged' => $converged,
            ]);
        }

        return [
            'iterations' => $iterationCount,
            'converged' => $converged,
            'max_change' => round($maxChange, 4),
        ];
    }

    /**
     * Perform single iteration of opponent adjustment.
     */
    private function performIteration(array $currentMetrics, Collection $games, Collection $metrics): array
    {
        // Calculate league averages for this iteration
        $leagueAvgDefense = array_sum($currentMetrics['currentDefense']) / count($currentMetrics['currentDefense']);
        $leagueAvgOffense = array_sum($currentMetrics['currentOffense']) / count($currentMetrics['currentOffense']);
        $leagueAvgTempo = array_sum($currentMetrics['currentTempo']) / count($currentMetrics['currentTempo']);

        // Calculate new adjusted values based on current opponent ratings
        $newOffense = [];
        $newDefense = [];
        $newTempo = [];

        foreach ($metrics as $metric) {
            $teamId = $metric->team_id;
            $teamGames = $games->filter(fn ($g) => $g->home_team_id == $teamId || $g->away_team_id == $teamId);

            $adjustedValues = $this->calculateTeamAdjustments(
                $teamId,
                $teamGames,
                $currentMetrics,
                $leagueAvgDefense,
                $leagueAvgOffense,
                $leagueAvgTempo
            );

            if ($adjustedValues['valid_games'] > 0) {
                $targetOffense = $adjustedValues['offense_sum'] / $adjustedValues['valid_games'];
                $targetDefense = $adjustedValues['defense_sum'] / $adjustedValues['valid_games'];
                $targetTempo = $adjustedValues['tempo_sum'] / $adjustedValues['valid_games'];
            } else {
                // No adjustment if no qualifying games
                $targetOffense = $currentMetrics['currentOffense'][$teamId];
                $targetDefense = $currentMetrics['currentDefense'][$teamId];
                $targetTempo = $currentMetrics['currentTempo'][$teamId];
            }

            // Apply damping: move partway toward target
            $newOffense[$teamId] = $currentMetrics['currentOffense'][$teamId] + $this->dampingFactor * ($targetOffense - $currentMetrics['currentOffense'][$teamId]);
            $newDefense[$teamId] = $currentMetrics['currentDefense'][$teamId] + $this->dampingFactor * ($targetDefense - $currentMetrics['currentDefense'][$teamId]);
            $newTempo[$teamId] = $currentMetrics['currentTempo'][$teamId] + $this->dampingFactor * ($targetTempo - $currentMetrics['currentTempo'][$teamId]);
        }

        return [
            'currentOffense' => $newOffense,
            'currentDefense' => $newDefense,
            'currentTempo' => $newTempo,
        ];
    }

    /**
     * Calculate adjustments for a single team based on opponent strength.
     */
    private function calculateTeamAdjustments(
        int $teamId,
        Collection $teamGames,
        array $currentMetrics,
        float $leagueAvgDefense,
        float $leagueAvgOffense,
        float $leagueAvgTempo
    ): array {
        $offenseSum = 0;
        $defenseSum = 0;
        $tempoSum = 0;
        $validGames = 0;

        foreach ($teamGames as $game) {
            $isHome = $game->home_team_id == $teamId;
            $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;

            // Skip if opponent doesn't meet minimum
            if (! isset($currentMetrics['currentDefense'][$opponentId])) {
                continue;
            }

            $teamStat = $game->teamStats->firstWhere('team_id', $teamId);
            if (! $teamStat) {
                continue;
            }

            $possessions = $teamStat->possessions ?? ($this->estimatePossessions)($teamStat);
            if ($possessions == 0) {
                continue;
            }

            $points = $teamStat->points ?? 0;

            // Offensive adjustment based on opponent's current defensive rating
            $rawOffEff = ($points / $possessions) * 100;
            $adjustedOffEff = $rawOffEff * ($leagueAvgDefense / $currentMetrics['currentDefense'][$opponentId]);
            $offenseSum += $adjustedOffEff;

            // Defensive adjustment based on opponent's current offensive rating
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);
            if ($opponentStat) {
                $oppPossessions = $opponentStat->possessions ?? ($this->estimatePossessions)($opponentStat);
                if ($oppPossessions > 0) {
                    $oppPoints = $opponentStat->points ?? 0;
                    $rawDefEff = ($oppPoints / $oppPossessions) * 100;
                    $adjustedDefEff = $rawDefEff * ($leagueAvgOffense / $currentMetrics['currentOffense'][$opponentId]);
                    $defenseSum += $adjustedDefEff;
                }
            }

            // Tempo adjustment
            $adjustedTempo = $possessions * ($leagueAvgTempo / $currentMetrics['currentTempo'][$opponentId]);
            $tempoSum += $adjustedTempo;

            $validGames++;
        }

        return [
            'offense_sum' => $offenseSum,
            'defense_sum' => $defenseSum,
            'tempo_sum' => $tempoSum,
            'valid_games' => $validGames,
        ];
    }

    /**
     * Calculate maximum change between iterations.
     */
    private function calculateMaxChange(array $old, array $new): float
    {
        $maxChange = 0;

        foreach ($old['currentOffense'] as $teamId => $oldValue) {
            $change = abs($new['currentOffense'][$teamId] - $oldValue);
            $maxChange = max($maxChange, $change);
        }

        foreach ($old['currentDefense'] as $teamId => $oldValue) {
            $change = abs($new['currentDefense'][$teamId] - $oldValue);
            $maxChange = max($maxChange, $change);
        }

        return $maxChange;
    }

    /**
     * Normalize metrics to baseline (typically 100).
     */
    private function normalizeToBaseline(array &$metrics): void
    {
        $adjAvgOffense = array_sum($metrics['currentOffense']) / count($metrics['currentOffense']);
        $adjAvgDefense = array_sum($metrics['currentDefense']) / count($metrics['currentDefense']);
        $adjAvgTempo = array_sum($metrics['currentTempo']) / count($metrics['currentTempo']);

        foreach ($metrics['currentOffense'] as $teamId => $offVal) {
            $metrics['currentOffense'][$teamId] = ($offVal / $adjAvgOffense) * $this->normalizationBaseline;
        }

        foreach ($metrics['currentDefense'] as $teamId => $defVal) {
            $metrics['currentDefense'][$teamId] = ($defVal / $adjAvgDefense) * $this->normalizationBaseline;
        }

        foreach ($metrics['currentTempo'] as $teamId => $tempoVal) {
            $metrics['currentTempo'][$teamId] = ($tempoVal / $adjAvgTempo) * $this->normalizationBaseline;
        }

        Log::debug('Normalization complete', [
            'sport' => $this->sport,
            'season' => $this->season,
            'baseline' => $this->normalizationBaseline,
            'avg_offense' => round($adjAvgOffense, 1),
            'avg_defense' => round($adjAvgDefense, 1),
            'avg_tempo' => round($adjAvgTempo, 1),
        ]);
    }

    /**
     * Save adjusted metrics back to database.
     */
    private function saveAdjustedMetrics(array $adjusted, Collection $metrics): void
    {
        $updated = 0;

        foreach ($metrics as $metric) {
            $teamId = $metric->team_id;

            $metric->update([
                'adj_offensive_efficiency' => round($adjusted['currentOffense'][$teamId], 1),
                'adj_defensive_efficiency' => round($adjusted['currentDefense'][$teamId], 1),
                'adj_net_rating' => round($adjusted['currentOffense'][$teamId] - $adjusted['currentDefense'][$teamId], 1),
                'adj_tempo' => round($adjusted['currentTempo'][$teamId], 1),
            ]);

            $updated++;
        }

        Log::info('Adjusted metrics saved to database', [
            'sport' => $this->sport,
            'season' => $this->season,
            'updated_count' => $updated,
        ]);
    }

    /**
     * Set iteration count on metrics (called separately after calculate()).
     */
    public function setIterationCount(Collection $metrics, int $iterationCount): void
    {
        foreach ($metrics as $metric) {
            $metric->update(['iteration_count' => $iterationCount]);
        }
    }
}
