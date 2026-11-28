<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractFootballSyncTeamStats;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\ESPN\FootballTeamStatParser;
use App\Support\ESPN\ObservedStatValue;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SyncTeamStats extends AbstractFootballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;

    public function execute(array $gameData, Model $game): int
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $validation = app(CfbGameDataValidator::class)->boxscore($gameData, $game);
        if ($validation['state'] !== 'complete') {
            return 0;
        }

        return DB::transaction(function () use ($gameData, $game, $validation): int {
            $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $game->load(['homeTeam', 'awayTeam']);
            $current = app(CfbGameDataValidator::class)->boxscore($gameData, $game);
            if ($current['state'] !== 'complete') {
                return 0;
            }
            $validation = $current;
            $synced = parent::execute($gameData, $game);
            if ($synced !== 2) {
                return 0;
            }

            foreach ([$game->homeTeam, $game->awayTeam] as $team) {
                TeamStat::where('game_id', $game->id)->where('team_id', $team->id)
                    ->update(['team_attributed_stats' => json_encode($validation['evidence']['team_attributed_stats'][$team->espn_id] ?? [])]);
            }

            return $synced;
        });
    }

    protected function additionalTeamAttributes(array $attributes, array $teamData, Model $game, array $gameData): array
    {
        if ($attributes['sacks_allowed'] !== null) {
            return $attributes;
        }
        $opponent = (int) $attributes['team_id'] === (int) $game->home_team_id ? $game->awayTeam : $game->homeTeam;
        $observed = null;
        foreach ($gameData['boxscore']['players'] ?? [] as $team) {
            if ((string) data_get($team, 'team.id') !== (string) $opponent?->espn_id) {
                continue;
            }
            foreach ($team['statistics'] ?? [] as $category) {
                if (($category['name'] ?? null) !== 'defensive') {
                    continue;
                }
                $keys = $category['keys'] ?? [];
                $totals = $category['totals'] ?? [];
                if (! is_array($keys) || ! is_array($totals) || count($keys) !== count($totals)
                    || count(array_keys($keys, 'sacks', true)) !== 1) {
                    continue;
                }
                $index = array_search('sacks', $keys, true);
                try {
                    $value = ObservedStatValue::integer($totals[$index] ?? null);
                } catch (DomainException) {
                    continue;
                }
                if ($observed !== null && $observed !== $value) {
                    throw new DomainException('Conflicting defensive sack totals.');
                }
                $observed = $value;
            }
        }
        $attributes['sacks_allowed'] = $observed;

        return $attributes;
    }

    protected function clearExisting(Model $game): void {}

    protected function storeStat(array $attributes): void
    {
        TeamStat::updateOrCreate(['game_id' => $attributes['game_id'], 'team_id' => $attributes['team_id']], $attributes);
    }

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
