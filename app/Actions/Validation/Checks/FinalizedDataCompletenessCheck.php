<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SportsDateWindowService;
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
        $dates = app(SportsDateWindowService::class);
        $window = $dates->forRange($dates->parseLocalDate()->subDays($lookbackDays), $dates->parseLocalDate());

        $games = $gameModel::query()
            ->with(['prediction', 'teamStats'])
            ->where('status', 'STATUS_FINAL')
            ->whereDate('game_date', '>=', $window->localStartDate())
            ->whereDate('game_date', '<=', $window->localEndDate())
            ->get();

        $totalGames = $games->count();
        $missingPlayerStatsCount = 0;
        $missingTeamStatsCount = 0;
        $missingPlaysCount = 0;
        $missingGradingCount = 0;
        $missingGameScoreCount = 0;
        $reconstructableMissingScoreCount = 0;
        $nonReconstructableMissingScoreCount = 0;
        $scoreConflictCount = 0;
        $flaggedGameIds = [];
        $sampleGames = [];

        foreach ($games as $game) {
            $flagged = false;
            $reasons = [];

            if (! $game->playerStats()->exists()) {
                $missingPlayerStatsCount++;
                $flagged = true;
                $reasons[] = 'missing_player_stats';
            }

            if (! $game->teamStats()->exists()) {
                $missingTeamStatsCount++;
                $flagged = true;
                $reasons[] = 'missing_team_stats';
            }

            if (! $game->plays()->exists()) {
                $missingPlaysCount++;
                $flagged = true;
                $reasons[] = 'missing_plays';
            }

            $scoreState = $this->scoreCompletenessState($sport, $game);

            if ($scoreState['missing_game_score']) {
                $missingGameScoreCount++;

                if ($scoreState['reconstructable_missing_score']) {
                    $reconstructableMissingScoreCount++;
                    $flagged = true;
                    $reasons[] = 'reconstructable_missing_game_score';
                } else {
                    $nonReconstructableMissingScoreCount++;
                    $reasons[] = 'missing_game_score_without_team_stat_runs';
                }
            }

            if ($scoreState['score_conflict']) {
                $scoreConflictCount++;
                $flagged = true;
                $reasons[] = 'score_conflict_with_team_stats';
            }

            $prediction = $game->prediction;
            $gameEndedLongEnoughAgo = $game->game_date !== null
                && $game->game_date->lt(now()->subHours($gradingGraceHours));

            if ($prediction !== null && $gameEndedLongEnoughAgo && $prediction->graded_at === null) {
                $missingGradingCount++;
                $flagged = true;
                $reasons[] = 'missing_prediction_grading';
            }

            if ($flagged) {
                $flaggedGameIds[] = (int) $game->getKey();
                $sampleGames[] = [
                    'game_id' => (int) $game->getKey(),
                    'espn_event_id' => $game->espn_event_id ?? null,
                    'matchup' => $game->short_name ?: $game->name,
                    'game_date' => $game->game_date?->toDateString(),
                    'status' => $game->status,
                    'reasons' => $reasons,
                ];
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $totalGames > 0 ? $problemGames / $totalGames : 0.0;
        $warnPct = (float) config('validation.thresholds.finalized_data_completeness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.finalized_data_completeness.problem_fail_pct', 0.20);

        $status = 'passing';
        $message = "Finalized data looks complete across {$totalGames} recent final games.";

        if ($reconstructableMissingScoreCount > 0 || $scoreConflictCount > 0) {
            $status = 'failing';
            $message = "{$reconstructableMissingScoreCount} reconstructable final score gap(s) and {$scoreConflictCount} final score conflict(s) need reconciliation.";
        } elseif ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$problemGames}/{$totalGames} recent final games are missing stats, plays, or grading artifacts.";
        } elseif ($nonReconstructableMissingScoreCount > 0) {
            $status = 'warning';
            $message = "{$nonReconstructableMissingScoreCount}/{$totalGames} recent final games have missing score columns but are not reconstructable from team stats yet.";
        }

        return [
            'check_type' => 'validation_finalized_data_completeness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "espn:sync-{$sport}-game-details",
            'metadata' => [
                'lookback_days' => $lookbackDays,
                'date_window' => $window->toArray(),
                'recent_final_games' => $totalGames,
                'games_missing_player_stats' => $missingPlayerStatsCount,
                'games_missing_team_stats' => $missingTeamStatsCount,
                'games_missing_plays' => $missingPlaysCount,
                'games_missing_grading' => $missingGradingCount,
                'games_missing_game_scores' => $missingGameScoreCount,
                'reconstructable_missing_game_scores' => $reconstructableMissingScoreCount,
                'non_reconstructable_missing_game_scores' => $nonReconstructableMissingScoreCount,
                'score_conflicts_with_team_stats' => $scoreConflictCount,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'sample_games' => array_slice($sampleGames, 0, 5),
                'grading_grace_hours' => $gradingGraceHours,
            ],
        ];
    }

    /**
     * @return array{missing_game_score: bool, reconstructable_missing_score: bool, score_conflict: bool}
     */
    private function scoreCompletenessState(string $sport, Model $game): array
    {
        $missingGameScore = $game->home_score === null || $game->away_score === null;

        if ($sport !== 'mlb') {
            return [
                'missing_game_score' => $missingGameScore,
                'reconstructable_missing_score' => false,
                'score_conflict' => false,
            ];
        }

        $homeStat = $game->teamStats
            ->first(fn (Model $stat): bool => (string) $stat->team_type === 'home' && (int) $stat->team_id === (int) $game->home_team_id);
        $awayStat = $game->teamStats
            ->first(fn (Model $stat): bool => (string) $stat->team_type === 'away' && (int) $stat->team_id === (int) $game->away_team_id);

        $homeRuns = $homeStat?->runs;
        $awayRuns = $awayStat?->runs;
        $hasTeamStatRuns = $homeRuns !== null && $awayRuns !== null;

        $homeScoreConflicts = $game->home_score !== null
            && $homeRuns !== null
            && (int) $game->home_score !== (int) $homeRuns;
        $awayScoreConflicts = $game->away_score !== null
            && $awayRuns !== null
            && (int) $game->away_score !== (int) $awayRuns;

        return [
            'missing_game_score' => $missingGameScore,
            'reconstructable_missing_score' => $missingGameScore && $hasTeamStatRuns && ! $homeScoreConflicts && ! $awayScoreConflicts,
            'score_conflict' => $homeScoreConflicts || $awayScoreConflicts,
        ];
    }
}
