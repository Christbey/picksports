<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CurrentDayGameDataFreshnessCheck implements ValidationCheck
{
    private const FINAL_STATUSES = ['STATUS_FINAL', 'final'];

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $gamesTable = $tables['games'] ?? null;
        $teamStatsTable = $tables['team_stats'] ?? null;
        $playerStatsTable = $tables['player_stats'] ?? null;
        $playsTable = $tables['plays'] ?? null;

        if (
            ! $gamesTable || ! $teamStatsTable || ! $playerStatsTable || ! $playsTable
            || ! Schema::hasTable($gamesTable)
            || ! Schema::hasTable($teamStatsTable)
            || ! Schema::hasTable($playerStatsTable)
            || ! Schema::hasTable($playsTable)
        ) {
            return null;
        }

        $dates = app(SportsDateWindowService::class);
        $todayWindow = $dates->forDate();
        $now = CarbonImmutable::now($dates->timezone());
        $inSeason = in_array((int) $now->month, (array) ($profile['active_months'] ?? []), true);
        $staleHours = (int) config('validation.thresholds.current_day_game_data.stale_after_hours', 8);
        $finalStatsGraceHours = (int) config('validation.thresholds.current_day_game_data.final_stats_grace_hours', 2);
        $warnPct = (float) config('validation.thresholds.current_day_game_data.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.current_day_game_data.problem_fail_pct', 0.20);

        $games = DB::table($gamesTable)
            ->whereDate('game_date', '>=', $todayWindow->localStartDate())
            ->whereDate('game_date', '<=', $todayWindow->localEndDate())
            ->get(['id', 'espn_event_id', 'short_name', 'name', 'status', 'game_date', 'updated_at']);

        $totalGames = $games->count();
        $staleGames = 0;
        $finalGamesMissingTeamStats = 0;
        $finalGamesMissingBothTeamStats = 0;
        $finalGamesMissingPlayerStats = 0;
        $finalGamesMissingPlays = 0;
        $flaggedGameIds = [];
        $sampleGames = [];

        foreach ($games as $game) {
            $gameId = (int) $game->id;
            $flagged = false;
            $reasons = [];

            $updatedAt = $game->updated_at ? CarbonImmutable::parse($game->updated_at) : null;
            $gameDate = $game->game_date ? CarbonImmutable::parse($game->game_date) : null;

            if ($inSeason && (! $updatedAt || $updatedAt->lt($now->subHours($staleHours)))) {
                $staleGames++;
                $flagged = true;
                $reasons[] = 'stale_game_row';
            }

            $isFinal = in_array((string) $game->status, self::FINAL_STATUSES, true);
            $gameIsPastGrace = $gameDate?->lt($now->subHours($finalStatsGraceHours)) ?? false;

            if ($isFinal && $gameIsPastGrace) {
                $teamStatsCount = DB::table($teamStatsTable)->where('game_id', $gameId)->count();
                if ($teamStatsCount === 0) {
                    $finalGamesMissingTeamStats++;
                    $flagged = true;
                    $reasons[] = 'missing_team_stats';
                }

                if ($teamStatsCount < 2) {
                    $finalGamesMissingBothTeamStats++;
                    $flagged = true;
                    $reasons[] = 'missing_both_team_stats';
                }

                if (! DB::table($playerStatsTable)->where('game_id', $gameId)->exists()) {
                    $finalGamesMissingPlayerStats++;
                    $flagged = true;
                    $reasons[] = 'missing_player_stats';
                }

                if (! DB::table($playsTable)->where('game_id', $gameId)->exists()) {
                    $finalGamesMissingPlays++;
                    $flagged = true;
                    $reasons[] = 'missing_plays';
                }
            }

            if ($flagged) {
                $flaggedGameIds[] = $gameId;
                $sampleGames[] = [
                    'game_id' => $gameId,
                    'espn_event_id' => $game->espn_event_id,
                    'matchup' => $game->short_name ?: $game->name,
                    'game_date' => $gameDate?->toDateString(),
                    'status' => $game->status,
                    'updated_at' => $updatedAt?->toIso8601String(),
                    'reasons' => $reasons,
                ];
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $totalGames > 0 ? $problemGames / $totalGames : 0.0;
        $status = 'passing';
        $message = "Today's game data looks fresh across {$totalGames} game(s).";

        if ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$problemGames}/{$totalGames} game(s) today have stale game rows or missing finalized stats.";
        }

        return [
            'check_type' => 'validation_current_day_game_data_freshness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "espn:sync-{$sport}-game-details",
            'metadata' => [
                'date' => $todayWindow->localStartDate(),
                'date_window' => $todayWindow->toArray(),
                'in_season' => $inSeason,
                'games_today' => $totalGames,
                'stale_games' => $staleGames,
                'final_games_missing_team_stats' => $finalGamesMissingTeamStats,
                'final_games_missing_both_team_stats' => $finalGamesMissingBothTeamStats,
                'final_games_missing_player_stats' => $finalGamesMissingPlayerStats,
                'final_games_missing_plays' => $finalGamesMissingPlays,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'sample_games' => array_slice($sampleGames, 0, 5),
                'stale_after_hours' => $staleHours,
                'final_stats_grace_hours' => $finalStatsGraceHours,
            ],
        ];
    }
}
