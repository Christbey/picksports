<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FuturesOddsFreshnessCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $enabled = (bool) ($profile['futures_enabled'] ?? false);

        if (! $enabled || ! Schema::hasTable('sports_futures_odds')) {
            return null;
        }

        $season = (int) now()->year;
        $staleHours = (int) config('validation.thresholds.futures_odds_freshness.stale_after_hours', 12);
        $minimumRows = (int) config('validation.thresholds.futures_odds_freshness.minimum_rows', 1);
        $offseasonContext = $this->offseasonContext($sport, $profile);
        $rows = DB::table('sports_futures_odds')
            ->where('sport', $sport)
            ->where(function ($query) use ($season) {
                $query->where('season', $season)
                    ->orWhereNull('season');
            });

        $rowCount = (int) (clone $rows)->count();
        $latestFetchedAt = (clone $rows)->max('fetched_at');
        $missingRows = $rowCount < $minimumRows;
        $stale = ! $latestFetchedAt || now()->parse($latestFetchedAt)->lt(now()->subHours($staleHours));

        if (($offseasonContext['offseason_without_active_games'] ?? false) === true && ($missingRows || $stale)) {
            return [
                'check_type' => 'validation_futures_odds_freshness',
                'status' => 'passing',
                'severity' => 'passing',
                'message' => "Futures odds are not required during {$sport} offseason with no active games.",
                'recommended_action' => null,
                'metadata' => [
                    'season' => $season,
                    'rows' => $rowCount,
                    'minimum_rows' => $minimumRows,
                    'latest_fetched_at' => $latestFetchedAt,
                    'stale_after_hours' => $staleHours,
                    'missing_rows' => $missingRows,
                    'stale' => $stale,
                ] + $offseasonContext,
            ];
        }

        $status = $missingRows || $stale ? 'failing' : 'passing';
        $message = $status === 'passing'
            ? "Futures odds are fresh with {$rowCount} row(s)."
            : "Futures odds are missing or stale with {$rowCount} row(s).";

        return [
            'check_type' => 'validation_futures_odds_freshness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "sports:sync-futures-odds --sport={$sport} --season={$season}",
            'metadata' => [
                'season' => $season,
                'rows' => $rowCount,
                'minimum_rows' => $minimumRows,
                'latest_fetched_at' => $latestFetchedAt,
                'stale_after_hours' => $staleHours,
                'missing_rows' => $missingRows,
                'stale' => $stale,
            ] + $offseasonContext,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function offseasonContext(string $sport, array $profile): array
    {
        $stage = app(SeasonStageService::class)->context($sport, (int) now()->year);
        $activeGames = $this->activeGameCount($profile);

        return [
            'stage' => $stage->stage,
            'stage_group' => $stage->stageGroup,
            'active_games_in_window' => $activeGames,
            'offseason_without_active_games' => in_array($stage->stageGroup, ['offseason', 'preseason'], true)
                && $activeGames === 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function activeGameCount(array $profile): int
    {
        $gamesTable = (string) data_get($profile, 'tables.games', '');

        if ($gamesTable === '' || ! Schema::hasTable($gamesTable) || ! Schema::hasColumn($gamesTable, 'game_date')) {
            return 0;
        }

        $dates = app(SportsDateWindowService::class);
        $windowDays = max(1, (int) ($profile['market_window_days'] ?? config('validation.market_window_days', 1)));
        $startDate = $dates->parseLocalDate();
        $endDate = $startDate->addDays($windowDays);
        $query = DB::table($gamesTable)
            ->whereDate('game_date', '>=', $startDate->toDateString())
            ->whereDate('game_date', '<=', $endDate->toDateString());

        if (Schema::hasColumn($gamesTable, 'status')) {
            $query->whereIn('status', [
                'STATUS_SCHEDULED',
                'STATUS_PRE_GAME',
                'STATUS_DELAYED',
                'STATUS_IN_PROGRESS',
                'scheduled',
                'in_progress',
            ]);
        }

        return (int) $query->count();
    }
}
