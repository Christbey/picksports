<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractFootballSyncTeamStats;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SyncTeamStats extends AbstractFootballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;

    public function execute(array $gameData, Model $game): int
    {
        if (! isset($gameData['boxscore']['teams'])) {
            return 0;
        }

        return DB::transaction(function () use ($gameData, $game): int {
            $synced = parent::execute($gameData, $game);
            $defensiveSacks = [];
            foreach ($gameData['boxscore']['players'] ?? [] as $teamBoxscore) {
                $espnId = $teamBoxscore['team']['id'] ?? null;
                foreach ($teamBoxscore['statistics'] ?? [] as $category) {
                    if (! $espnId || ($category['name'] ?? null) !== 'defensive') {
                        continue;
                    }
                    $index = array_search('sacks', $category['keys'] ?? [], true);
                    $value = $index === false ? null : ($category['totals'][$index] ?? null);
                    // Use ESPN's team aggregate, never a potentially incomplete athlete sum.
                    if (is_numeric($value) && (float) $value >= 0 && floor((float) $value) === (float) $value) {
                        $defensiveSacks[(string) $espnId] = (int) $value;
                    }
                }
            }

            $teams = Team::query()->whereIn('id', [$game->home_team_id, $game->away_team_id])->get()->keyBy('id');
            foreach ([$game->home_team_id => $game->away_team_id, $game->away_team_id => $game->home_team_id] as $teamId => $opponentId) {
                $opponentEspnId = $teams->get($opponentId)?->espn_id;
                if ($opponentEspnId !== null && array_key_exists((string) $opponentEspnId, $defensiveSacks)) {
                    TeamStat::query()->where('game_id', $game->id)->where('team_id', $teamId)
                        ->whereNull('sacks_allowed')->update(['sacks_allowed' => $defensiveSacks[(string) $opponentEspnId]]);
                }
            }

            return $synced;
        });
    }

    protected function parseTeamStats(array $statistics): array
    {
        $parsed = [];

        foreach ($statistics as $stat) {
            $name = $stat['name'];
            $value = $stat['displayValue'];

            match ($name) {
                'firstDowns' => $parsed['firstDowns'] = (int) $value,
                'totalYards' => $parsed['totalYards'] = (int) $value,
                'netPassingYards' => $parsed['passingYards'] = (int) $value,
                'completionAttempts' => $this->parseFraction($value, 'completions', 'passingAttempts', $parsed),
                'passingTouchdowns' => $parsed['passingTouchdowns'] = (int) $value,
                'rushingYards' => $parsed['rushingYards'] = (int) $value,
                'rushingAttempts' => $parsed['rushingAttempts'] = (int) $value,
                'rushingTouchdowns' => $parsed['rushingTouchdowns'] = (int) $value,
                'interceptions' => $parsed['interceptions'] = (int) $value,
                'fumblesLost' => $parsed['fumblesLost'] = (int) $value,
                'possessionTime' => $parsed['possessionTime'] = $value,
                'thirdDownEff' => $this->parseFraction($value, 'thirdDownConversions', 'thirdDownAttempts', $parsed),
                'fourthDownEff' => $this->parseFraction($value, 'fourthDownConversions', 'fourthDownAttempts', $parsed),
                'redZoneAttempts' => $parsed['redZoneAttempts'] = (int) $value,
                'redZoneScores' => $parsed['redZoneScores'] = (int) $value,
                'redZoneEfficiency' => $this->parseFraction($value, 'redZoneScores', 'redZoneAttempts', $parsed),
                'totalPenaltiesYards' => $this->parseFraction($value, 'penalties', 'penaltyYards', $parsed),
                'sacksYardsLost' => $this->parseFraction($value, 'sacksAllowed', null, $parsed),
                default => null,
            };
        }

        return $parsed;
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
