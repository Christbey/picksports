<?php

namespace App\Services\TeamMetrics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeamRecordService
{
    public function applyRecords(Collection $metrics, string $gamesTable): void
    {
        if ($metrics->isEmpty()) {
            return;
        }

        if (! preg_match('/^[a-z_]+$/', $gamesTable)) {
            throw new \InvalidArgumentException('Invalid games table name.');
        }

        $hasSeasonColumn = Schema::hasColumn($gamesTable, 'season');
        $hasSeasonTypeColumn = Schema::hasColumn($gamesTable, 'season_type');
        $metricSeasons = $metrics
            ->pluck('season')
            ->filter(fn ($season) => $season !== null && $season !== '')
            ->map(fn ($season) => (int) $season)
            ->unique()
            ->values();
        $metricSeasonTypes = $metrics
            ->pluck('season_type')
            ->filter(fn ($seasonType) => $seasonType !== null && $seasonType !== '')
            ->map(fn ($seasonType) => (string) $seasonType)
            ->unique()
            ->values();

        if ($hasSeasonColumn && $hasSeasonTypeColumn && $metricSeasons->isNotEmpty() && $metricSeasonTypes->isNotEmpty()) {
            $this->applySeasonTypeAwareRecords($metrics, $gamesTable, $metricSeasons, $metricSeasonTypes);

            return;
        }

        if ($hasSeasonColumn && $metricSeasons->isNotEmpty()) {
            $seasonList = $metricSeasons->implode(',');
            $records = collect(DB::select("
                SELECT team_id, season,
                    SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN won = 0 THEN 1 ELSE 0 END) as losses
                FROM (
                    SELECT home_team_id as team_id, season, CASE WHEN home_score > away_score THEN 1 ELSE 0 END as won
                    FROM {$gamesTable} WHERE status = 'STATUS_FINAL' AND season IN ({$seasonList})
                    UNION ALL
                    SELECT away_team_id as team_id, season, CASE WHEN away_score > home_score THEN 1 ELSE 0 END as won
                    FROM {$gamesTable} WHERE status = 'STATUS_FINAL' AND season IN ({$seasonList})
                ) results
                GROUP BY team_id, season
            "))->keyBy(fn ($row) => "{$row->team_id}-{$row->season}");

            $metrics->each(function ($metric) use ($records) {
                $key = "{$metric->team_id}-{$metric->season}";
                $record = $records->get($key);
                $metric->setAttribute('wins', (int) ($record->wins ?? 0));
                $metric->setAttribute('losses', (int) ($record->losses ?? 0));
            });

            return;
        }

        $records = collect(DB::select("
            SELECT team_id,
                SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN won = 0 THEN 1 ELSE 0 END) as losses
            FROM (
                SELECT home_team_id as team_id, CASE WHEN home_score > away_score THEN 1 ELSE 0 END as won
                FROM {$gamesTable} WHERE status = 'STATUS_FINAL'
                UNION ALL
                SELECT away_team_id as team_id, CASE WHEN away_score > home_score THEN 1 ELSE 0 END as won
                FROM {$gamesTable} WHERE status = 'STATUS_FINAL'
            ) results
            GROUP BY team_id
        "))->keyBy('team_id');

        $metrics->each(function ($metric) use ($records) {
            $record = $records->get($metric->team_id);
            $metric->setAttribute('wins', (int) ($record->wins ?? 0));
            $metric->setAttribute('losses', (int) ($record->losses ?? 0));
        });
    }

    protected function applySeasonTypeAwareRecords(
        Collection $metrics,
        string $gamesTable,
        Collection $metricSeasons,
        Collection $metricSeasonTypes
    ): void {
        $sportSlug = $this->sportSlugFromGamesTable($gamesTable);
        $seasonList = $metricSeasons->implode(',');
        $seasonTypeCandidatesByMetricType = $metricSeasonTypes
            ->mapWithKeys(fn (string $seasonType) => [
                $seasonType => $this->resolveSeasonTypeCandidatesForSport($sportSlug, $seasonType),
            ]);
        $allSeasonTypeCandidates = $seasonTypeCandidatesByMetricType
            ->flatten()
            ->filter(fn ($seasonType) => $seasonType !== null && $seasonType !== '')
            ->unique()
            ->values();

        $recordsQuery = DB::table($gamesTable)
            ->selectRaw("
                home_team_id as team_id,
                season,
                CAST(season_type AS CHAR) as season_type,
                SUM(CASE WHEN home_score > away_score THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN home_score <= away_score THEN 1 ELSE 0 END) as losses
            ")
            ->where('status', 'STATUS_FINAL')
            ->whereIn('season', $metricSeasons->all())
            ->when(
                $allSeasonTypeCandidates->isNotEmpty(),
                fn ($query) => $query->whereIn('season_type', $allSeasonTypeCandidates->all())
            )
            ->groupBy('home_team_id', 'season', 'season_type')
            ->unionAll(
                DB::table($gamesTable)
                    ->selectRaw("
                        away_team_id as team_id,
                        season,
                        CAST(season_type AS CHAR) as season_type,
                        SUM(CASE WHEN away_score > home_score THEN 1 ELSE 0 END) as wins,
                        SUM(CASE WHEN away_score <= home_score THEN 1 ELSE 0 END) as losses
                    ")
                    ->where('status', 'STATUS_FINAL')
                    ->whereIn('season', $metricSeasons->all())
                    ->when(
                        $allSeasonTypeCandidates->isNotEmpty(),
                        fn ($query) => $query->whereIn('season_type', $allSeasonTypeCandidates->all())
                    )
                    ->groupBy('away_team_id', 'season', 'season_type')
            );

        $records = DB::query()
            ->fromSub($recordsQuery, 'season_type_results')
            ->selectRaw('team_id, season, season_type, SUM(wins) as wins, SUM(losses) as losses')
            ->groupBy('team_id', 'season', 'season_type')
            ->get();

        $metrics->each(function ($metric) use ($records, $seasonTypeCandidatesByMetricType) {
            $metricSeasonType = (string) ($metric->season_type ?? '');
            $candidates = $seasonTypeCandidatesByMetricType->get($metricSeasonType, [$metricSeasonType]);

            $matchingRecords = $records->filter(function ($record) use ($metric, $candidates) {
                return (int) $record->team_id === (int) $metric->team_id
                    && (int) $record->season === (int) $metric->season
                    && in_array((string) $record->season_type, array_map('strval', $candidates), true);
            });

            $metric->setAttribute('wins', (int) $matchingRecords->sum('wins'));
            $metric->setAttribute('losses', (int) $matchingRecords->sum('losses'));
        });
    }

    protected function sportSlugFromGamesTable(string $gamesTable): ?string
    {
        if (! str_ends_with($gamesTable, '_games')) {
            return null;
        }

        return substr($gamesTable, 0, -strlen('_games'));
    }

    /**
     * @return array<int, int|string>
     */
    protected function resolveSeasonTypeCandidatesForSport(?string $sportSlug, int|string|null $seasonType): array
    {
        if ($sportSlug === null || $sportSlug === '' || $seasonType === null || $seasonType === '') {
            return [];
        }

        $typeNames = config("{$sportSlug}.season.type_names", []);
        $typesByKey = config("{$sportSlug}.season.types", []);
        $candidates = [$seasonType, (string) $seasonType];

        if (is_string($seasonType) && isset($typeNames[$seasonType])) {
            $candidates[] = $typeNames[$seasonType];
        }

        if (is_string($seasonType) && isset($typesByKey[$seasonType])) {
            $resolved = $typesByKey[$seasonType];
            $candidates[] = $resolved;
            $candidates[] = (string) $resolved;
        }

        if (is_numeric($seasonType)) {
            $code = (int) $seasonType;
            $matchedKey = array_search($code, $typesByKey, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = $typeNames[$matchedKey];
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => $value !== null && $value !== ''
        )));
    }
}
