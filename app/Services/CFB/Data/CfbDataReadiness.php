<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\TeamStat;

class CfbDataReadiness
{
    public function coreBoxscoreReady(Game $game): bool
    {
        if ($game->status !== 'STATUS_FINAL') {
            return false;
        }
        $box = $this->forGame($game)['components']['boxscore'];
        if ($box['state'] === 'complete' && $box['accepted']) {
            return true;
        }
        $check = GameDataCheck::where('game_id', $game->id)->where('component', CfbCfbdTeamBoxscoreSync::COMPONENT)
            ->where('validator_version', CfbCfbdTeamBoxscoreSync::VERSION)->where('state', 'complete')
            ->whereNotNull('accepted_at')->latest('accepted_at')->latest('id')->first();
        if (! $check) {
            return false;
        }
        foreach (['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score'] as $field) {
            if ((string) data_get($check->evidence, 'identity.'.$field) !== (string) $game->{$field}) {
                return false;
            }
        }
        foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
            $stat = TeamStat::where('game_id', $game->id)->where('team_id', $teamId)->first();
            $expected = data_get($check->evidence, 'team_stats.'.$teamId);
            if (! $stat || ! is_array($expected)) {
                return false;
            }
            foreach ($expected as $field => $value) {
                $actual = $stat->getAttribute($field);
                if ($value === null ? $actual !== null : $actual === null || (string) $actual !== (string) $value) {
                    return false;
                }
            }
        }

        return true;
    }

    public function forGame(Game $game): array
    {
        $states = [];
        foreach (['boxscore', 'plays'] as $component) {
            $check = GameDataCheck::where('game_id', $game->id)->where('component', $component)
                ->where('validator_version', CfbGameDataValidator::VERSION)->orderByDesc('updated_at')->orderByDesc('id')->first();
            $states[$component] = ['state' => $check?->state ?? 'unverified', 'accepted' => $check?->accepted_at !== null,
                'source_hash' => $check?->source_hash, 'discrepancies' => $check?->discrepancies ?? ['not_audited']];
        }

        return ['ready' => collect($states)->every(fn ($s) => $s['state'] === 'complete' && $s['accepted']), 'components' => $states];
    }
}
