<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScheduleWindowIntegrityCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $gamesTable = $profile['tables']['games'] ?? null;

        if (! is_string($gamesTable) || ! Schema::hasTable($gamesTable)) {
            return null;
        }

        $windowDays = (int) ($profile['window_days'] ?? config('validation.window_days', 7));
        $dates = app(SportsDateWindowService::class);
        $stageContext = app(SeasonStageService::class)->context($sport, null, null, $windowDays);

        $columns = $this->columns($gamesTable);
        $games = DB::table($gamesTable)
            ->whereIn('id', $stageContext->visibleGameIds)
            ->get($columns);

        $missingCoreFields = [];
        $dateLeakage = [];

        foreach ($games as $game) {
            $gameId = (int) $game->id;
            $reasons = [];

            if (! ($game->home_team_id ?? null) || ! ($game->away_team_id ?? null)) {
                $reasons[] = 'missing_teams';
            }

            if (! ($game->status ?? null)) {
                $reasons[] = 'missing_status';
            }

            if (! ($game->game_date ?? null)) {
                $reasons[] = 'missing_game_date';
            }

            if ($reasons !== []) {
                $missingCoreFields[] = [
                    'game_id' => $gameId,
                    'reasons' => $reasons,
                ];
            }

            $displayDate = $dates->gameDateForDisplay($game->game_date ?? null, $game->game_time ?? null);
            if (
                $displayDate !== null
                && ($displayDate < $stageContext->activeWindow->localStartDate()
                    || $displayDate > $stageContext->activeWindow->localEndDate())
            ) {
                $dateLeakage[] = [
                    'game_id' => $gameId,
                    'stored_game_date' => (string) ($game->game_date ?? ''),
                    'game_time' => $game->game_time ?? null,
                    'display_game_date' => $displayDate,
                ];
            }
        }

        $duplicateEspnEventIds = $this->duplicateEspnEventIds($gamesTable, $stageContext->activeWindow->localStartDate(), $stageContext->activeWindow->localEndDate());
        $problemCount = count($missingCoreFields) + count($dateLeakage) + count($duplicateEspnEventIds);
        $status = $problemCount > 0 ? 'failing' : 'passing';

        return [
            'check_type' => 'validation_schedule_window_integrity',
            'status' => $status,
            'severity' => $status,
            'message' => $problemCount > 0
                ? "{$problemCount} {$sport} schedule integrity issue(s) found across the active date/week/month windows."
                : strtoupper($sport).' schedule integrity looks clean across the active date/week/month windows.',
            'recommended_action' => "sports:operations-sentinel --sport={$sport}",
            'metadata' => [
                'window_days' => $windowDays,
                'season_stage' => $stageContext->toArray(),
                'date_window' => $stageContext->activeWindow->toArray(),
                'visible_games' => $games->count(),
                'active_games' => count($stageContext->activeGameIds),
                'missing_core_fields' => array_slice($missingCoreFields, 0, 10),
                'date_leakage' => array_slice($dateLeakage, 0, 10),
                'duplicate_espn_event_ids' => array_slice($duplicateEspnEventIds, 0, 10),
                'coverage_by_date' => $this->coverageBy($games, 'game_date'),
                'coverage_by_week' => Schema::hasColumn($gamesTable, 'week') ? $this->coverageBy($games, 'week') : [],
                'coverage_by_month' => $this->coverageByMonth($games),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function columns(string $gamesTable): array
    {
        return collect([
            'id',
            'espn_event_id',
            'season',
            'season_type',
            'week',
            'game_date',
            'game_time',
            'status',
            'home_team_id',
            'away_team_id',
            'short_name',
            'name',
        ])
            ->filter(fn (string $column): bool => Schema::hasColumn($gamesTable, $column))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{espn_event_id:string,count:int}>
     */
    private function duplicateEspnEventIds(string $gamesTable, string $fromDate, string $toDate): array
    {
        if (! Schema::hasColumn($gamesTable, 'espn_event_id')) {
            return [];
        }

        return DB::table($gamesTable)
            ->select('espn_event_id', DB::raw('COUNT(*) as row_count'))
            ->whereNotNull('espn_event_id')
            ->whereDate('game_date', '>=', $fromDate)
            ->whereDate('game_date', '<=', $toDate)
            ->groupBy('espn_event_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'espn_event_id' => (string) $row->espn_event_id,
                'count' => (int) $row->row_count,
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function coverageBy($games, string $field): array
    {
        return $games
            ->groupBy(fn ($game): string => $field === 'game_date'
                ? (substr((string) ($game->{$field} ?? 'unknown'), 0, 10) ?: 'unknown')
                : (string) ($game->{$field} ?? 'unknown'))
            ->map(fn ($rows): int => $rows->count())
            ->sortKeys()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function coverageByMonth($games): array
    {
        return $games
            ->groupBy(fn ($game): string => substr((string) ($game->game_date ?? 'unknown'), 0, 7) ?: 'unknown')
            ->map(fn ($rows): int => $rows->count())
            ->sortKeys()
            ->all();
    }
}
