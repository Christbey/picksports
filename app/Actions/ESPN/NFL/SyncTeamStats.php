<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractFootballSyncTeamStats;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Services\ESPN\FootballTeamStatParser;
use Illuminate\Database\Eloquent\Model;

class SyncTeamStats extends AbstractFootballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;

    protected function parseTeamStats(array $statistics): array
    {
        return app(FootballTeamStatParser::class)->parse($statistics);
    }

    protected function sportSpecificAttributes(array $stats): array
    {
        return [
            'passing_completions' => $stats['completions'] ?? null,
            'passing_attempts' => $stats['passingAttempts'] ?? null,
            'passing_touchdowns' => $stats['passingTouchdowns'] ?? null,
            'interceptions' => $stats['interceptions'] ?? null,
            'rushing_attempts' => $stats['rushingAttempts'] ?? null,
            'rushing_touchdowns' => $stats['rushingTouchdowns'] ?? null,
            'fumbles' => $stats['fumbles'] ?? null,
            'fumbles_lost' => $stats['fumblesLost'] ?? null,
            'sacks_allowed' => $stats['sacksAllowed'] ?? null,
            'red_zone_attempts' => $stats['redZoneAttempts'] ?? null,
            'red_zone_scores' => $stats['redZoneScores'] ?? null,
            'time_of_possession' => $stats['possessionTime'] ?? null,
        ];
    }

    protected function resolveTeamType(Model $team, Model $game, array $teamData): ?string
    {
        return ($team->id === $game->home_team_id) ? 'home' : 'away';
    }
}
