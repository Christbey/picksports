<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
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

        $games = DB::table($gamesTable)
            ->leftJoin($weatherTable, "{$weatherTable}.game_id", '=', "{$gamesTable}.id")
            ->whereDate("{$gamesTable}.game_date", '>=', now()->startOfDay()->toDateString())
            ->whereDate("{$gamesTable}.game_date", '<=', now()->copy()->addDays($windowDays)->toDateString())
            ->whereIn("{$gamesTable}.status", $activeStatuses)
            ->get([
                "{$gamesTable}.id",
                "{$gamesTable}.game_date",
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
        $missingWeatherGameIds = [];
        $staleWeatherGameIds = [];
        $unknownRoofGameIds = [];

        foreach ($games as $game) {
            $flagged = false;

            if (! $game->weather_id) {
                $missingWeather++;
                $flagged = true;
                $missingWeatherGameIds[] = (int) $game->id;
            } else {
                $weatherUpdatedAt = $game->weather_updated_at ? now()->parse($game->weather_updated_at) : null;

                if (! $weatherUpdatedAt || $weatherUpdatedAt->lt(now()->subHours($staleHours))) {
                    $staleWeather++;
                    $flagged = true;
                    $staleWeatherGameIds[] = (int) $game->id;
                }

                if ($sport === 'mlb' && ! (bool) $game->is_indoor && $game->roof_status === 'unknown_retractable') {
                    $unknownRoof++;
                    $flagged = true;
                    $unknownRoofGameIds[] = (int) $game->id;
                }
            }

            if ($flagged) {
                $flaggedGameIds[] = (int) $game->id;
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $totalGames > 0 ? $problemGames / $totalGames : 0.0;
        $status = 'passing';
        $message = "Weather coverage looks healthy for {$totalGames} upcoming games.";

        if ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
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
                'games_missing_weather' => $missingWeather,
                'games_with_stale_weather' => $staleWeather,
                'games_with_unknown_roof_status' => $unknownRoof,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'sample_missing_weather_game_ids' => array_slice(array_values(array_unique($missingWeatherGameIds)), 0, 5),
                'sample_stale_weather_game_ids' => array_slice(array_values(array_unique($staleWeatherGameIds)), 0, 5),
                'sample_unknown_roof_game_ids' => array_slice(array_values(array_unique($unknownRoofGameIds)), 0, 5),
                'stale_after_hours' => $staleHours,
            ],
        ];
    }
}
