<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GameCoverageCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $teamsTable = $tables['teams'] ?? null;
        $gamesTable = $tables['games'] ?? null;

        if (! $teamsTable || ! $gamesTable || ! Schema::hasTable($teamsTable) || ! Schema::hasTable($gamesTable)) {
            return null;
        }

        $windowDays = (int) ($profile['window_days'] ?? config('validation.window_days', 7));
        $expectedPerDay = (int) ($profile['expected_games_per_day'] ?? 0);
        $inSeason = in_array((int) now()->month, (array) ($profile['active_months'] ?? []), true);
        $stageContext = app(SeasonStageService::class)->context($sport);
        $season = (int) ($stageContext->season ?? now()->year);
        $eligibleTeamIds = $this->eligibleTeamIds($sport, $teamsTable, $season);

        $totalTeams = $eligibleTeamIds->count();

        if ($totalTeams === 0) {
            return [
                'check_type' => 'validation_game_coverage',
                'status' => 'failing',
                'message' => 'No teams found in database.',
                'metadata' => [
                    'total_teams' => 0,
                    'teams_with_games' => 0,
                    'teams_missing_games' => 0,
                ],
            ];
        }

        $homeTeamIds = DB::table($gamesTable)
            ->where('season', $season)
            ->whereNotNull('home_team_id')
            ->pluck('home_team_id');
        $awayTeamIds = DB::table($gamesTable)
            ->where('season', $season)
            ->whereNotNull('away_team_id')
            ->pluck('away_team_id');

        $teamsWithGamesIds = $homeTeamIds
            ->merge($awayTeamIds)
            ->unique()
            ->intersect($eligibleTeamIds)
            ->values();

        $teamsWithGames = $teamsWithGamesIds->count();
        $missingTeams = max($totalTeams - $teamsWithGames, 0);
        $seasonGames = DB::table($gamesTable)
            ->where('season', $season)
            ->count();

        $upcomingGames = DB::table($gamesTable)
            ->where('game_date', '>=', now()->startOfDay())
            ->where('game_date', '<=', now()->addDays($windowDays))
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'])
            ->count();

        $missingPct = $totalTeams > 0 ? $missingTeams / $totalTeams : 1.0;
        $warnPct = (float) config('validation.thresholds.game_coverage.missing_teams_warn_pct', 0.0);
        $failPct = (float) config('validation.thresholds.game_coverage.missing_teams_fail_pct', 0.05);
        $minUpcomingFactor = (float) config('validation.thresholds.game_coverage.min_upcoming_games_factor', 0.5);
        $expectedUpcoming = $expectedPerDay * $windowDays;
        $minUpcoming = (int) floor($expectedUpcoming * $minUpcomingFactor);

        $status = 'passing';
        $message = "Team game coverage looks healthy. {$teamsWithGames}/{$totalTeams} teams have games this season.";

        if ($seasonGames === 0 && in_array($stageContext->stageGroup, ['offseason', 'preseason'], true)) {
            $message = "No {$sport} games found for {$season}; season schedule coverage is not expected yet.";
        } elseif ($missingPct >= $failPct) {
            $status = 'failing';
            $message = "{$missingTeams}/{$totalTeams} teams are missing games this season.";
        } elseif ($missingPct > $warnPct) {
            $status = 'warning';
            $message = "{$missingTeams}/{$totalTeams} teams are missing games this season.";
        }

        if ($inSeason && $upcomingGames < $minUpcoming) {
            $status = $status === 'failing' ? 'failing' : 'warning';
            $message .= " Upcoming game volume is low ({$upcomingGames} in {$windowDays} days).";
        }

        return [
            'check_type' => 'validation_game_coverage',
            'status' => $status,
            'message' => $message,
            'metadata' => [
                'season' => $season,
                'in_season' => $inSeason,
                'stage' => $stageContext->stage,
                'stage_group' => $stageContext->stageGroup,
                'total_teams' => $totalTeams,
                'teams_with_games' => $teamsWithGames,
                'teams_missing_games' => $missingTeams,
                'season_games' => $seasonGames,
                'upcoming_games' => $upcomingGames,
                'expected_upcoming_games' => $expectedUpcoming,
            ],
        ];
    }

    private function eligibleTeamIds(string $sport, string $teamsTable, int $season): Collection
    {
        if (
            $sport === 'cfb'
            && Schema::hasTable('cfb_team_season_affiliations')
            && Schema::hasColumn('cfb_team_season_affiliations', 'subdivision')
        ) {
            $ids = DB::table($teamsTable)
                ->join('cfb_team_season_affiliations', function ($join) use ($teamsTable, $season): void {
                    $join->on('cfb_team_season_affiliations.team_id', '=', "{$teamsTable}.id")
                        ->where('cfb_team_season_affiliations.season', '=', $season)
                        ->where('cfb_team_season_affiliations.subdivision', '=', config('cfb.teams.divisions.fbs', 'FBS'));
                })
                ->pluck("{$teamsTable}.id")
                ->unique()
                ->values();

            if ($ids->isNotEmpty()) {
                return $ids;
            }
        }

        return DB::table($teamsTable)->pluck('id')->unique()->values();
    }
}
