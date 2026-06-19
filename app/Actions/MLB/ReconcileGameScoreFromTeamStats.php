<?php

namespace App\Actions\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\TeamStat;
use App\Support\MLB\MlbGamePhase;

class ReconcileGameScoreFromTeamStats
{
    /**
     * @return array{
     *     game_id:int,
     *     status:string,
     *     home_score_before:int|null,
     *     away_score_before:int|null,
     *     home_score_after:int|null,
     *     away_score_after:int|null,
     *     source:string,
     *     reason:string,
     *     dry_run:bool,
     *     forced:bool
     * }
     */
    public function execute(Game|int $game, bool $force = false, bool $dryRun = false): array
    {
        $game = $game instanceof Game
            ? $game
            : Game::query()->findOrFail($game);

        $homeScoreBefore = $this->nullableInt($game->home_score);
        $awayScoreBefore = $this->nullableInt($game->away_score);

        $base = [
            'game_id' => (int) $game->getKey(),
            'home_score_before' => $homeScoreBefore,
            'away_score_before' => $awayScoreBefore,
            'home_score_after' => $homeScoreBefore,
            'away_score_after' => $awayScoreBefore,
            'source' => 'mlb_team_stats.runs',
            'dry_run' => $dryRun,
            'forced' => $force,
        ];

        if (! MlbGamePhase::isFinal($game)) {
            return [
                ...$base,
                'status' => 'skipped',
                'reason' => 'game_not_final',
            ];
        }

        $homeRuns = $this->teamRuns($game, 'home');
        $awayRuns = $this->teamRuns($game, 'away');

        if ($homeRuns === null || $awayRuns === null) {
            return [
                ...$base,
                'status' => 'skipped',
                'reason' => 'missing_team_stats_runs',
            ];
        }

        $homeConflicts = $homeScoreBefore !== null && $homeScoreBefore !== $homeRuns;
        $awayConflicts = $awayScoreBefore !== null && $awayScoreBefore !== $awayRuns;

        if (($homeConflicts || $awayConflicts) && ! $force) {
            return [
                ...$base,
                'status' => 'conflict',
                'home_score_after' => $homeRuns,
                'away_score_after' => $awayRuns,
                'reason' => 'game_score_conflicts_with_team_stats_runs',
            ];
        }

        if ($homeScoreBefore === $homeRuns && $awayScoreBefore === $awayRuns) {
            return [
                ...$base,
                'status' => 'unchanged',
                'reason' => 'scores_already_match_team_stats_runs',
            ];
        }

        if (! $dryRun) {
            $game->forceFill([
                'home_score' => $homeRuns,
                'away_score' => $awayRuns,
            ])->save();
        }

        return [
            ...$base,
            'status' => 'updated',
            'home_score_after' => $homeRuns,
            'away_score_after' => $awayRuns,
            'reason' => $force && ($homeConflicts || $awayConflicts)
                ? 'force_reconciled_score_from_team_stats'
                : 'filled_missing_final_score_from_team_stats',
        ];
    }

    private function teamRuns(Game $game, string $teamType): ?int
    {
        $teamId = $teamType === 'home' ? $game->home_team_id : $game->away_team_id;

        $runs = TeamStat::query()
            ->where('game_id', $game->id)
            ->where('team_id', $teamId)
            ->where('team_type', $teamType)
            ->value('runs');

        if ($runs === null) {
            $runs = TeamStat::query()
                ->where('game_id', $game->id)
                ->where('team_type', $teamType)
                ->value('runs');
        }

        return is_numeric($runs) ? (int) $runs : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
