<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;

class CfbDataReadiness
{
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
