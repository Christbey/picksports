<?php

namespace App\Services\CFB;

class PlayEpaDataService
{
    /**
     * @var array<int, true>
     */
    private const ADMIN_DOWN_VALUES = [
        -1 => true,
        0 => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ADMIN_PLAY_TYPES = [
        'coin toss' => true,
        'end period' => true,
        'end of game' => true,
        'end of half' => true,
        'official timeout' => true,
        'timeout' => true,
        'injury timeout' => true,
        'penalty' => true,
    ];

    public function isEpaEligiblePlay(object $play): bool
    {
        $playType = strtolower(trim((string) ($play->play_type ?? '')));
        $playText = strtolower((string) ($play->play_text ?? ''));
        $down = is_numeric($play->down ?? null) ? (int) $play->down : null;
        $distance = is_numeric($play->distance ?? null) ? (int) $play->distance : null;
        $yardsToEndzone = is_numeric($play->yards_to_endzone ?? null) ? (int) $play->yards_to_endzone : null;

        if ($playType !== '' && isset(self::ADMIN_PLAY_TYPES[$playType])) {
            return false;
        }

        if (str_contains($playText, 'no play')) {
            return false;
        }

        if ($down === null || isset(self::ADMIN_DOWN_VALUES[$down]) || $down < 1 || $down > 4) {
            return false;
        }

        if ($distance === null || $distance < 0) {
            return false;
        }

        if ($yardsToEndzone === null || $yardsToEndzone < 1 || $yardsToEndzone > 100) {
            return false;
        }

        return true;
    }
}
