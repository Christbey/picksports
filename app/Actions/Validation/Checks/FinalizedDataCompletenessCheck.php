<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use Illuminate\Database\Eloquent\Model;

class FinalizedDataCompletenessCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $gameModel = $profile['models']['game'] ?? null;

        if (! is_string($gameModel) || ! class_exists($gameModel)) {
            return null;
        }

        /** @var class-string<Model> $gameModel */
        $lookbackDays = (int) config('validation.thresholds.finalized_data_completeness.lookback_days', 14);
        $gradingGraceHours = (int) config('validation.thresholds.finalized_data_completeness.grading_grace_hours', 6);

        $games = $gameModel::query()
            ->with(['prediction'])
            ->where('status', 'STATUS_FINAL')
            ->whereDate('game_date', '>=', now()->copy()->subDays($lookbackDays)->toDateString())
            ->get();

        $totalGames = $games->count();
        $missingPlayerStatsCount = 0;
        $missingTeamStatsCount = 0;
        $missingPlaysCount = 0;
        $missingGradingCount = 0;
        $flaggedGameIds = [];

        foreach ($games as $game) {
            $flagged = false;

            if (! $game->playerStats()->exists()) {
                $missingPlayerStatsCount++;
                $flagged = true;
            }

            if (! $game->teamStats()->exists()) {
                $missingTeamStatsCount++;
                $flagged = true;
            }

            if (! $game->plays()->exists()) {
                $missingPlaysCount++;
                $flagged = true;
            }

            $prediction = $game->prediction;
            $gameEndedLongEnoughAgo = $game->game_date !== null
                && $game->game_date->lt(now()->subHours($gradingGraceHours));

            if ($prediction !== null && $gameEndedLongEnoughAgo && $prediction->graded_at === null) {
                $missingGradingCount++;
                $flagged = true;
            }

            if ($flagged) {
                $flaggedGameIds[] = (int) $game->getKey();
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $totalGames > 0 ? $problemGames / $totalGames : 0.0;
        $warnPct = (float) config('validation.thresholds.finalized_data_completeness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.finalized_data_completeness.problem_fail_pct', 0.20);

        $status = 'passing';
        $message = "Finalized data looks complete across {$totalGames} recent final games.";

        if ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$problemGames}/{$totalGames} recent final games are missing stats, plays, or grading artifacts.";
        }

        return [
            'check_type' => 'validation_finalized_data_completeness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "espn:sync-{$sport}-game-details",
            'metadata' => [
                'lookback_days' => $lookbackDays,
                'recent_final_games' => $totalGames,
                'games_missing_player_stats' => $missingPlayerStatsCount,
                'games_missing_team_stats' => $missingTeamStatsCount,
                'games_missing_plays' => $missingPlaysCount,
                'games_missing_grading' => $missingGradingCount,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'grading_grace_hours' => $gradingGraceHours,
            ],
        ];
    }
}
