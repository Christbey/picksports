<?php

namespace App\Services\NBA;

class PlayEpaDataService
{
    /** @var array<string,true> */
    private const ADMIN_PLAY_TYPES = [
        'timeout' => true,
        'end period' => true,
        'end game' => true,
        'jump ball' => true,
        'violation' => true,
        'substitution' => true,
        'official timeout' => true,
    ];

    public function isEpaEligiblePlay(object $play): bool
    {
        if (! is_numeric($play->possession_team_id ?? null)) {
            return false;
        }

        $playType = strtolower(trim((string) ($play->play_type ?? '')));
        $playText = strtolower((string) ($play->play_text ?? ''));

        if ($playType !== '' && isset(self::ADMIN_PLAY_TYPES[$playType])) {
            return false;
        }

        if (str_contains($playText, 'end of') || str_contains($playText, 'timeout')) {
            return false;
        }

        $hasScore = is_numeric($play->home_score ?? null) && is_numeric($play->away_score ?? null);

        return $hasScore;
    }
}
