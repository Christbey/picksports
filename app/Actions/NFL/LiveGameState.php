<?php

namespace App\Actions\NFL;

/** Validated score/clock inputs only; this is not a possession-aware model. */
final class LiveGameState
{
    /** @return array{seconds_remaining: int, seconds_elapsed: int, effective_length: int, margin: int, total: int}|null */
    public static function fromGame(object $game): ?array
    {
        if (! in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true)) {
            return null;
        }

        $period = filter_var($game->period, FILTER_VALIDATE_INT);
        $homeScore = filter_var($game->home_score, FILTER_VALIDATE_INT);
        $awayScore = filter_var($game->away_score, FILTER_VALIDATE_INT);
        if ($period === false || $period < 1 || $homeScore === false || $awayScore === false || $homeScore < 0 || $awayScore < 0) {
            return null;
        }

        $clock = $game->game_clock;
        if (! is_string($clock) || ! preg_match('/^\d{1,2}:[0-5]\d$/D', $clock)) {
            return null;
        }

        [$minutes, $seconds] = array_map('intval', explode(':', $clock));
        $clockSeconds = $minutes * 60 + $seconds;
        $postseason = in_array(strtolower((string) ($game->season_type ?? '')), ['postseason', 'post', '3'], true);
        // NFL Rule 16: regular-season OT is 10 minutes; postseason periods are 15.
        $periodLength = $period <= 4 || $postseason ? 900 : 600;
        if ($clockSeconds > $periodLength || (! $postseason && $period > 5)) {
            return null;
        }

        $effectiveLength = 3600 + max(0, $period - 4) * $periodLength;
        $remaining = $period <= 4 ? (4 - $period) * 900 + $clockSeconds : $clockSeconds;

        return [
            'seconds_remaining' => $remaining,
            'seconds_elapsed' => $effectiveLength - $remaining,
            'effective_length' => $effectiveLength,
            'margin' => $homeScore - $awayScore,
            'total' => $homeScore + $awayScore,
        ];
    }

    /**
     * Provisional heuristic, not a fitted or calibrated probability model.
     * Remaining scoring uncertainty shrinks with time, but never vanishes while
     * the feed still says live (0:00 may have a play/review/extra point pending).
     * The scale and floor are explicit heuristics, not learned NFL coefficients.
     */
    public static function homeProbability(int $margin, int $secondsRemaining, float $preGameProbability, bool $overtime = false): float
    {
        $remainingFraction = max(0.0, min(1.0, $secondsRemaining / 3600));
        $prior = is_finite($preGameProbability) ? max(0.01, min(0.99, $preGameProbability)) : 0.5;
        $priorWeight = $overtime ? 0.0 : $remainingFraction;
        $remainingMarginScale = sqrt(1.0 + (143.0 * $remainingFraction));
        $logOdds = log($prior / (1 - $prior)) * $priorWeight + $margin / $remainingMarginScale;

        return max(0.01, min(0.99, 1 / (1 + exp(-max(-700.0, min(700.0, $logOdds))))));
    }
}
