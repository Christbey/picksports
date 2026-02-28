<?php

namespace App\Services\TeamMetrics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamRecordService
{
    public function applyRecords(Collection $metrics, string $gamesTable): void
    {
        if (! preg_match('/^[a-z_]+$/', $gamesTable)) {
            throw new \InvalidArgumentException('Invalid games table name.');
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
