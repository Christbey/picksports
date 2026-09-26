<?php

namespace App\Services\CFB\Scoring;

class CfbOvertimeRules
{
    public static function state(int $season, int $round): array
    {
        if ($season < 2016 || $round < 1) {
            throw new \InvalidArgumentException('Unsupported CFB overtime rule era');
        }

        return ['tries_only' => ($season >= 2021 && $round >= 3) || ($season >= 2019 && $season <= 2020 && $round >= 5),
            'mandatory_two' => $round >= ($season >= 2021 ? 2 : 3)];
    }

    public static function validOutcome(array $state, int $points, int $opponent): bool
    {
        if ($state['tries_only']) {
            return in_array($points, [0, 1, 2], true) && in_array($opponent, [0, 1, 2], true);
        }

        return in_array($points, $state['mandatory_two'] ? [0, 2, 3, 6, 8] : [0, 2, 3, 6, 7, 8], true) && in_array($opponent, [0, 1, 2, 6], true);
    }
}
