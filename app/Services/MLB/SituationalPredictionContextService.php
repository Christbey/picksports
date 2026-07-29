<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\TeamMetric;
use App\Models\MLB\TeamStat;

class SituationalPredictionContextService
{
    /**
     * @return array{
     *   bullpen: array<string, mixed>,
     *   handedness: array<string, mixed>,
     *   spread_adjustment: float,
     *   total_adjustment: float
     * }
     */
    public function forGame(Game $game, int $homeTeamId, int $awayTeamId): array
    {
        $historicalReconstruction = (string) $game->status === (string) config('mlb.statuses.final', 'STATUS_FINAL');
        $homeBullpenFatigue = $this->bullpenFatigueScore($game, $homeTeamId);
        $awayBullpenFatigue = $this->bullpenFatigueScore($game, $awayTeamId);
        $bullpenQuality = app(BullpenRatingService::class)->contextForGame($game, $homeTeamId, $awayTeamId);

        $homeOpponentPitcherHand = $this->probablePitcherThrowingHand($game->probable_away_pitcher_espn_id, $awayTeamId);
        $awayOpponentPitcherHand = $this->probablePitcherThrowingHand($game->probable_home_pitcher_espn_id, $homeTeamId);
        $homeHandednessEdge = $historicalReconstruction
            ? 0.0
            : $this->lineupHandednessEdge($homeTeamId, $homeOpponentPitcherHand);
        $awayHandednessEdge = $historicalReconstruction
            ? 0.0
            : $this->lineupHandednessEdge($awayTeamId, $awayOpponentPitcherHand);
        $advancedRatings = $this->advancedRatingsContext($game, $homeTeamId, $awayTeamId);
        $starterForm = $this->starterFormContext($game, $homeTeamId, $awayTeamId);

        $bullpenSpreadAdjustment = round(
            ($awayBullpenFatigue - $homeBullpenFatigue)
            * (float) config('mlb.prediction.situational.bullpen.spread_weight', 0.3),
            2
        );
        $bullpenTotalAdjustment = round(
            ($homeBullpenFatigue + $awayBullpenFatigue)
            * (float) config('mlb.prediction.situational.bullpen.total_weight', 0.22),
            2
        );

        $handednessSpreadAdjustment = round(
            ($homeHandednessEdge - $awayHandednessEdge)
            * (float) config('mlb.prediction.situational.handedness.spread_weight', 0.45),
            2
        );
        $handednessTotalAdjustment = round(
            ($homeHandednessEdge + $awayHandednessEdge)
            * (float) config('mlb.prediction.situational.handedness.total_weight', 0.16),
            2
        );

        return [
            'bullpen' => [
                'home_fatigue' => round($homeBullpenFatigue, 3),
                'away_fatigue' => round($awayBullpenFatigue, 3),
                'spread_adjustment' => $bullpenSpreadAdjustment,
                'total_adjustment' => $bullpenTotalAdjustment,
            ],
            'bullpen_quality' => $bullpenQuality,
            'handedness' => [
                'applied' => ! $historicalReconstruction,
                'pregame_safe' => ! $historicalReconstruction,
                'safety_reason' => $historicalReconstruction
                    ? 'current_roster_membership_disabled_for_historical_reconstruction'
                    : 'current_pregame_roster',
                'home_edge' => round($homeHandednessEdge, 3),
                'away_edge' => round($awayHandednessEdge, 3),
                'home_opponent_pitcher_hand' => $homeOpponentPitcherHand,
                'away_opponent_pitcher_hand' => $awayOpponentPitcherHand,
                'spread_adjustment' => $handednessSpreadAdjustment,
                'total_adjustment' => $handednessTotalAdjustment,
            ],
            'advanced_ratings' => $advancedRatings,
            'starter_form' => $starterForm,
            'spread_adjustment' => round(
                $bullpenSpreadAdjustment
                + (float) ($bullpenQuality['spread_adjustment'] ?? 0.0)
                + $handednessSpreadAdjustment
                + (float) ($advancedRatings['spread_adjustment'] ?? 0.0)
                + (float) ($starterForm['spread_adjustment'] ?? 0.0),
                2
            ),
            'total_adjustment' => round(
                $bullpenTotalAdjustment
                + (float) ($bullpenQuality['total_adjustment'] ?? 0.0)
                + $handednessTotalAdjustment
                + (float) ($advancedRatings['total_adjustment'] ?? 0.0)
                + (float) ($starterForm['total_adjustment'] ?? 0.0),
                2
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function advancedRatingsContext(Game $game, int $homeTeamId, int $awayTeamId): array
    {
        if (! (bool) config('mlb.prediction.situational.advanced_ratings.enabled', true)) {
            return [
                'home_offense_score' => 0.0,
                'away_offense_score' => 0.0,
                'home_prevention_score' => 0.0,
                'away_prevention_score' => 0.0,
                'spread_adjustment' => 0.0,
                'total_adjustment' => 0.0,
            ];
        }

        $homeMetric = $this->teamMetricForGame($game, $homeTeamId);
        $awayMetric = $this->teamMetricForGame($game, $awayTeamId);

        $homeOffense = $this->offenseScore($homeMetric);
        $awayOffense = $this->offenseScore($awayMetric);
        $homePrevention = $this->preventionScore($homeMetric);
        $awayPrevention = $this->preventionScore($awayMetric);

        $spreadWeight = (float) config('mlb.prediction.situational.advanced_ratings.spread_weight', 0.18);
        $totalWeight = (float) config('mlb.prediction.situational.advanced_ratings.total_weight', 0.16);
        $maxSpread = (float) config('mlb.prediction.situational.advanced_ratings.max_spread_adjustment', 0.6);
        $maxTotal = (float) config('mlb.prediction.situational.advanced_ratings.max_total_adjustment', 0.7);

        $homeMatchupScore = $homeOffense - $awayPrevention;
        $awayMatchupScore = $awayOffense - $homePrevention;

        $spreadAdjustment = $this->clamp(
            ($homeMatchupScore - $awayMatchupScore) * $spreadWeight,
            $maxSpread
        );
        $totalAdjustment = $this->clamp(
            ($homeMatchupScore + $awayMatchupScore) * $totalWeight,
            $maxTotal
        );

        return [
            'home_offense_score' => round($homeOffense, 3),
            'away_offense_score' => round($awayOffense, 3),
            'home_prevention_score' => round($homePrevention, 3),
            'away_prevention_score' => round($awayPrevention, 3),
            'home_calculation_date' => $homeMetric?->calculation_date?->toDateString(),
            'away_calculation_date' => $awayMetric?->calculation_date?->toDateString(),
            'spread_adjustment' => round($spreadAdjustment, 2),
            'total_adjustment' => round($totalAdjustment, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function starterFormContext(Game $game, int $homeTeamId, int $awayTeamId): array
    {
        if (! (bool) config('mlb.prediction.situational.starter_form.enabled', true)) {
            return [
                'home_score' => 0.0,
                'away_score' => 0.0,
                'spread_adjustment' => 0.0,
                'total_adjustment' => 0.0,
            ];
        }

        $homeScore = $this->starterFormScore($game, $game->probable_home_pitcher_espn_id, $homeTeamId);
        $awayScore = $this->starterFormScore($game, $game->probable_away_pitcher_espn_id, $awayTeamId);

        $spreadAdjustment = ($homeScore - $awayScore)
            * (float) config('mlb.prediction.situational.starter_form.spread_weight', 0.25);
        $totalAdjustment = -($homeScore + $awayScore)
            * (float) config('mlb.prediction.situational.starter_form.total_weight', 0.10);

        return [
            'home_score' => round($homeScore, 3),
            'away_score' => round($awayScore, 3),
            'spread_adjustment' => round($spreadAdjustment, 2),
            'total_adjustment' => round($totalAdjustment, 2),
        ];
    }

    private function bullpenFatigueScore(Game $game, int $teamId): float
    {
        $recentStats = TeamStat::query()
            ->where('team_id', $teamId)
            ->join('mlb_games', 'mlb_team_stats.game_id', '=', 'mlb_games.id')
            ->whereHas('game', function ($query) use ($game) {
                $query->where('season', (int) $game->season)
                    ->whereIn('season_type', array_map('strval', (array) config('mlb.season.analytics_types', [2, 3])))
                    ->where('status', config('mlb.statuses.final', 'STATUS_FINAL'))
                    ->whereDate('game_date', '<', $game->game_date);
            })
            ->orderByDesc('mlb_games.game_date')
            ->orderByDesc('mlb_games.id')
            ->select('mlb_team_stats.*')
            ->with('game:id,game_date')
            ->limit((int) config('mlb.prediction.situational.bullpen.lookback_games', 3))
            ->get();

        if ($recentStats->isEmpty()) {
            return 0.0;
        }

        $weights = [1.0, 0.7, 0.5];
        $weightedScore = 0.0;
        $totalWeight = 0.0;

        foreach ($recentStats->values() as $index => $stat) {
            $weight = $weights[$index] ?? 0.4;
            $pitchersUsed = max(0, (int) ($stat->pitchers_used ?? 0) - 3) * 0.35;
            $totalPitches = max(0, ((int) ($stat->total_pitches ?? 0)) - 130) / 25;
            $baseScore = $pitchersUsed + ($totalPitches * 0.15);

            $daysRest = $stat->game?->game_date?->diffInDays($game->game_date) ?? null;
            if ($daysRest !== null && $daysRest <= 1) {
                $baseScore += 0.35;
            } elseif ($daysRest !== null && $daysRest === 2) {
                $baseScore += 0.15;
            }

            $weightedScore += $baseScore * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? min(2.5, $weightedScore / $totalWeight) : 0.0;
    }

    private function probablePitcherThrowingHand(?string $espnId, int $teamId): ?string
    {
        if (! $espnId) {
            return null;
        }

        $hand = Player::query()
            ->where('espn_id', $espnId)
            ->value('throwing_hand');

        if (! is_string($hand) || $hand === '') {
            return null;
        }

        return strtoupper($hand);
    }

    private function lineupHandednessEdge(int $teamId, ?string $opponentPitcherHand): float
    {
        if (! in_array($opponentPitcherHand, ['L', 'R'], true)) {
            return 0.0;
        }

        $hitters = Player::query()
            ->where('team_id', $teamId)
            ->where('position', '!=', 'P')
            ->get(['batting_hand']);

        if ($hitters->isEmpty()) {
            return 0.0;
        }

        $left = $hitters->where('batting_hand', 'L')->count();
        $right = $hitters->where('batting_hand', 'R')->count();
        $switch = $hitters->where('batting_hand', 'S')->count();
        $total = max(1, $hitters->count());

        if ($opponentPitcherHand === 'R') {
            return (($left + ($switch * 0.5)) - $right) / $total;
        }

        return (($right + ($switch * 0.5)) - $left) / $total;
    }

    private function teamMetricForGame(Game $game, int $teamId): ?TeamMetric
    {
        return TeamMetric::query()
            ->where('team_id', $teamId)
            ->where('season', (int) $game->season)
            ->where('season_type', (string) $game->season_type)
            ->whereDate('calculation_date', '<=', $game->game_date)
            ->orderByDesc('calculation_date')
            ->orderByDesc('id')
            ->first();
    }

    private function offenseScore(?TeamMetric $metric): float
    {
        if (! $metric) {
            return 0.0;
        }

        $opsBaseline = (float) config('mlb.prediction.situational.advanced_ratings.baseline_ops', 0.720);
        $opsDivisor = max(0.001, (float) config('mlb.prediction.situational.advanced_ratings.ops_divisor', 0.080));
        $ops = ((float) ($metric->ops ?? $opsBaseline) - $opsBaseline) / $opsDivisor;

        return $this->clamp($ops, 2.5);
    }

    private function preventionScore(?TeamMetric $metric): float
    {
        if (! $metric) {
            return 0.0;
        }

        $whipBaseline = (float) config('mlb.prediction.situational.advanced_ratings.baseline_whip', 1.280);
        $whipDivisor = max(0.001, (float) config('mlb.prediction.situational.advanced_ratings.whip_divisor', 0.180));
        $eraBaseline = (float) config('mlb.prediction.situational.advanced_ratings.baseline_team_era', 4.20);
        $eraDivisor = max(0.001, (float) config('mlb.prediction.situational.advanced_ratings.era_divisor', 1.20));

        $whipScore = ($whipBaseline - (float) ($metric->whip ?? $whipBaseline)) / $whipDivisor;
        $eraScore = ($eraBaseline - (float) ($metric->team_era ?? $eraBaseline)) / $eraDivisor;

        return $this->clamp(($whipScore * 0.55) + ($eraScore * 0.45), 2.5);
    }

    private function starterFormScore(Game $game, ?string $espnId, int $teamId): float
    {
        if (! $espnId) {
            return 0.0;
        }

        $player = Player::query()
            ->where('espn_id', $espnId)
            ->first();

        if (! $player) {
            return 0.0;
        }

        $lookback = max(2, (int) config('mlb.prediction.situational.starter_form.lookback_starts', 4));
        $trendDivisor = max(1.0, (float) config('mlb.prediction.situational.starter_form.trend_divisor', 60.0));

        $ratings = PitcherEloRating::query()
            ->where('player_id', $player->id)
            ->where('team_id', $teamId)
            ->whereDate('date', '<', $game->game_date)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($lookback)
            ->pluck('elo_rating')
            ->map(fn ($rating): float => (float) $rating)
            ->values();

        if ($ratings->count() < 2) {
            return 0.0;
        }

        $latest = (float) $ratings->first();
        $baseline = $ratings->slice(1)->avg();

        return round($this->clamp(($latest - (float) $baseline) / $trendDivisor, 1.0), 3);
    }

    private function clamp(float $value, float $maxMagnitude): float
    {
        $maxMagnitude = abs($maxMagnitude);

        return max(-$maxMagnitude, min($maxMagnitude, $value));
    }
}
