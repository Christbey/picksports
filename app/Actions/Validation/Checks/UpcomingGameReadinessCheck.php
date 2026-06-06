<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpcomingGameReadinessCheck implements ValidationCheck
{
    private const ACTIVE_STATUSES = [
        'STATUS_SCHEDULED',
        'STATUS_PRE_GAME',
        'STATUS_DELAYED',
        'scheduled',
    ];

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $gamesTable = $tables['games'] ?? null;
        $teamsTable = $tables['teams'] ?? null;
        $predictionsTable = "{$sport}_predictions";
        $teamMetricsTable = "{$sport}_team_metrics";

        if (
            ! $gamesTable || ! $teamsTable
            || ! Schema::hasTable($gamesTable)
            || ! Schema::hasTable($teamsTable)
            || ! Schema::hasTable($predictionsTable)
            || ! Schema::hasTable($teamMetricsTable)
        ) {
            return null;
        }

        $windowDays = (int) ($profile['window_days'] ?? config('validation.window_days', 7));
        $upcomingGames = DB::table($gamesTable)
            ->whereDate('game_date', '>=', now()->startOfDay()->toDateString())
            ->whereDate('game_date', '<=', now()->copy()->addDays($windowDays)->toDateString())
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get([
                'id',
                'espn_event_id',
                'home_team_id',
                'away_team_id',
                'season',
                'season_type',
                'game_date',
                'status',
                'odds_data',
                'odds_updated_at',
                'updated_at',
            ]);

        $missingTeams = 0;
        $missingEspnEventIds = 0;
        $missingPredictions = 0;
        $missingOdds = 0;
        $missingTeamMetrics = 0;
        $staleGameRows = 0;
        $flaggedGameIds = [];
        $sampleGames = [];
        $staleHours = (int) config('validation.thresholds.upcoming_game_readiness.stale_after_hours', 12);

        foreach ($upcomingGames as $game) {
            $gameId = (int) $game->id;
            $reasons = [];

            if (! $game->home_team_id || ! $game->away_team_id) {
                $missingTeams++;
                $reasons[] = 'missing_teams';
            }

            if (! $game->espn_event_id) {
                $missingEspnEventIds++;
                $reasons[] = 'missing_espn_event_id';
            }

            if (! DB::table($predictionsTable)->where('game_id', $gameId)->exists()) {
                $missingPredictions++;
                $reasons[] = 'missing_prediction';
            }

            $oddsData = $this->decodeOddsData($game->odds_data ?? null);
            if (! is_array($oddsData) || empty($oddsData['bookmakers'])) {
                $missingOdds++;
                $reasons[] = 'missing_odds';
            }

            if (! $this->hasMetricsForBothTeams($teamMetricsTable, $game)) {
                $missingTeamMetrics++;
                $reasons[] = 'missing_team_metrics';
            }

            $updatedAt = $game->updated_at ? CarbonImmutable::parse($game->updated_at) : null;
            if (! $updatedAt || $updatedAt->lt(now()->subHours($staleHours))) {
                $staleGameRows++;
                $reasons[] = 'stale_game_row';
            }

            if ($reasons !== []) {
                $flaggedGameIds[] = $gameId;
                $sampleGames[] = [
                    'game_id' => $gameId,
                    'game_date' => $game->game_date ? CarbonImmutable::parse($game->game_date)->toDateString() : null,
                    'status' => $game->status,
                    'reasons' => $reasons,
                ];
            }
        }

        $totalGames = $upcomingGames->count();
        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $totalGames > 0 ? $problemGames / $totalGames : 0.0;
        $warnPct = (float) config('validation.thresholds.upcoming_game_readiness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.upcoming_game_readiness.problem_fail_pct', 0.20);
        $status = 'passing';
        $message = "Upcoming game pages look ready for {$totalGames} active game(s) in the next {$windowDays} days.";

        if ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$problemGames}/{$totalGames} upcoming game page(s) are missing pregame readiness data.";
        }

        return [
            'check_type' => 'validation_upcoming_game_readiness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "sports:operations-sentinel --sport={$sport}",
            'metadata' => [
                'window_days' => $windowDays,
                'upcoming_games' => $totalGames,
                'games_missing_teams' => $missingTeams,
                'games_missing_espn_event_ids' => $missingEspnEventIds,
                'games_missing_predictions' => $missingPredictions,
                'games_missing_odds' => $missingOdds,
                'games_missing_team_metrics' => $missingTeamMetrics,
                'stale_game_rows' => $staleGameRows,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'sample_games' => array_slice($sampleGames, 0, 5),
                'stale_after_hours' => $staleHours,
            ],
        ];
    }

    private function hasMetricsForBothTeams(string $teamMetricsTable, object $game): bool
    {
        if (! $game->home_team_id || ! $game->away_team_id || ! $game->season) {
            return false;
        }

        $query = DB::table($teamMetricsTable)
            ->where('season', $game->season)
            ->whereIn('team_id', [(int) $game->home_team_id, (int) $game->away_team_id]);

        if (Schema::hasColumn($teamMetricsTable, 'season_type') && $game->season_type !== null) {
            $query->where('season_type', $game->season_type);
        }

        return $query->distinct('team_id')->count('team_id') >= 2;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeOddsData(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
