<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WeatherCompletenessCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $gamesTable = $tables['games'] ?? null;
        $weatherTable = $tables['weather'] ?? null;
        $weatherCommand = $profile['weather_command'] ?? null;

        if (
            ! is_string($gamesTable)
            || ! is_string($weatherTable)
            || ! is_string($weatherCommand)
            || ! Schema::hasTable($gamesTable)
            || ! Schema::hasTable($weatherTable)
        ) {
            return null;
        }

        $windowDays = (int) ($profile['window_days'] ?? config('validation.window_days', 7));
        $staleHours = (int) config('validation.thresholds.weather_completeness.stale_after_hours', 8);
        $warnPct = (float) config('validation.thresholds.weather_completeness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.weather_completeness.problem_fail_pct', 0.20);
        $activeStatuses = ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'scheduled', 'in_progress'];
        $stageContext = app(SeasonStageService::class)->context($sport, null, null, $windowDays);
        $marketReadyGameIds = $stageContext->marketReadyGameIds;

        $games = DB::table($gamesTable)
            ->leftJoin($weatherTable, "{$weatherTable}.game_id", '=', "{$gamesTable}.id")
            ->whereDate("{$gamesTable}.game_date", '>=', now()->startOfDay()->toDateString())
            ->whereDate("{$gamesTable}.game_date", '<=', now()->copy()->addDays($windowDays)->toDateString())
            ->whereIn("{$gamesTable}.status", $activeStatuses)
            ->get([
                "{$gamesTable}.id",
                "{$gamesTable}.espn_event_id",
                "{$gamesTable}.short_name",
                "{$gamesTable}.name",
                "{$gamesTable}.game_date",
                "{$gamesTable}.venue_name",
                "{$gamesTable}.venue_city",
                "{$gamesTable}.venue_state",
                "{$weatherTable}.id as weather_id",
                "{$weatherTable}.updated_at as weather_updated_at",
                "{$weatherTable}.is_indoor",
                "{$weatherTable}.roof_status",
            ]);

        $totalGames = $games->count();
        $missingWeather = 0;
        $staleWeather = 0;
        $unknownRoof = 0;
        $flaggedGameIds = [];
        $blockingFlaggedGameIds = [];
        $blockingMissingWeatherGameIds = [];
        $missingWeatherGameIds = [];
        $staleWeatherGameIds = [];
        $unknownRoofGameIds = [];
        $sampleGames = [];

        foreach ($games as $game) {
            $flagged = false;
            $reasons = [];

            if (! $game->weather_id) {
                $missingWeather++;
                $flagged = true;
                $missingWeatherGameIds[] = (int) $game->id;
                $reasons[] = 'missing_weather';
            } else {
                $weatherUpdatedAt = $game->weather_updated_at ? now()->parse($game->weather_updated_at) : null;

                if (! $weatherUpdatedAt || $weatherUpdatedAt->lt(now()->subHours($staleHours))) {
                    $staleWeather++;
                    $flagged = true;
                    $staleWeatherGameIds[] = (int) $game->id;
                    $reasons[] = 'stale_weather';
                }

                if ($sport === 'mlb' && ! (bool) $game->is_indoor && $game->roof_status === 'unknown_retractable') {
                    $unknownRoof++;
                    $flagged = true;
                    $unknownRoofGameIds[] = (int) $game->id;
                    $reasons[] = 'unknown_retractable_roof_status';
                }
            }

            if ($flagged) {
                $flaggedGameIds[] = (int) $game->id;
                $sampleGames[] = $this->sampleGame($game, $reasons, in_array((int) $game->id, $marketReadyGameIds, true));

                if (in_array((int) $game->id, $marketReadyGameIds, true)) {
                    $blockingFlaggedGameIds[] = (int) $game->id;

                    if (! $game->weather_id) {
                        $blockingMissingWeatherGameIds[] = (int) $game->id;
                    }
                }
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $totalGames > 0 ? $problemGames / $totalGames : 0.0;
        $blockingProblemGames = count(array_unique($blockingFlaggedGameIds));
        $blockingMissingWeatherGames = count(array_unique($blockingMissingWeatherGameIds));
        $blockingProblemPct = count($marketReadyGameIds) > 0 ? $blockingMissingWeatherGames / count($marketReadyGameIds) : 0.0;
        $status = 'passing';
        $message = "Weather coverage looks healthy for {$totalGames} upcoming games.";

        if ($blockingMissingWeatherGames > 0) {
            $status = $blockingProblemPct >= $failPct ? 'failing' : ($blockingProblemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$blockingMissingWeatherGames}/".count($marketReadyGameIds).' market-ready active games are missing weather data.';
        } elseif ($blockingProblemGames > 0) {
            $status = 'warning';
            $message = "{$blockingProblemGames}/".count($marketReadyGameIds).' market-ready active games have stale or incomplete weather context.';
        } elseif ($problemGames > 0) {
            $status = $problemPct >= $warnPct ? 'warning' : 'passing';
            $message = "{$problemGames}/{$totalGames} upcoming games have missing, stale, or incomplete weather data.";
        }

        return [
            'check_type' => 'validation_weather_completeness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => $weatherCommand,
            'metadata' => [
                'window_days' => $windowDays,
                'upcoming_games' => $totalGames,
                'market_ready_games' => count($marketReadyGameIds),
                'market_ready_weather_problem_games' => $blockingProblemGames,
                'market_ready_missing_weather_games' => $blockingMissingWeatherGames,
                'games_missing_weather' => $missingWeather,
                'games_with_stale_weather' => $staleWeather,
                'games_with_unknown_roof_status' => $unknownRoof,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'sample_market_ready_weather_problem_game_ids' => array_slice(array_values(array_unique($blockingFlaggedGameIds)), 0, 5),
                'sample_market_ready_missing_weather_game_ids' => array_slice(array_values(array_unique($blockingMissingWeatherGameIds)), 0, 5),
                'sample_missing_weather_game_ids' => array_slice(array_values(array_unique($missingWeatherGameIds)), 0, 5),
                'sample_stale_weather_game_ids' => array_slice(array_values(array_unique($staleWeatherGameIds)), 0, 5),
                'sample_unknown_roof_game_ids' => array_slice(array_values(array_unique($unknownRoofGameIds)), 0, 5),
                'sample_games' => array_slice($sampleGames, 0, 5),
                'stale_after_hours' => $staleHours,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $reasons
     * @return array<string, mixed>
     */
    private function sampleGame(object $game, array $reasons, bool $marketReady): array
    {
        return [
            'game_id' => (int) $game->id,
            'espn_event_id' => $game->espn_event_id,
            'matchup' => $game->short_name ?: $game->name,
            'game_date' => $game->game_date ? now()->parse($game->game_date)->toDateString() : null,
            'venue' => trim(implode(', ', array_filter([
                $game->venue_name,
                $game->venue_city,
                $game->venue_state,
            ]))),
            'market_ready' => $marketReady,
            'weather_id' => $game->weather_id,
            'weather_updated_at' => $game->weather_updated_at ? now()->parse($game->weather_updated_at)->toIso8601String() : null,
            'is_indoor' => $game->is_indoor,
            'roof_status' => $game->roof_status,
            'reasons' => $reasons,
        ];
    }
}
