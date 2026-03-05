<?php

namespace App\Services\TeamMetrics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeamRecordService
{
    public function applyRecords(Collection $metrics, string $gamesTable): void
    {
        if (! preg_match('/^[a-z_]+$/', $gamesTable)) {
            throw new \InvalidArgumentException('Invalid games table name.');
        }

        $hasSeasonColumn = Schema::hasColumn($gamesTable, 'season');
        $metricSeasons = $metrics
            ->pluck('season')
            ->filter(fn ($season) => $season !== null && $season !== '')
            ->map(fn ($season) => (int) $season)
            ->unique()
            ->values();

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
}
