<?php

namespace App\Support\Sports;

final class CbbSeasonTypes
{
    public const REGULAR = [2, 'regular', 'regular_season', 'Regular Season'];

    public const POSTSEASON = [3, 'postseason', 'Postseason', 'Post Season'];

    public const ANALYTICS = [...self::REGULAR, ...self::POSTSEASON];

    public static function analytics(): array
    {
        return (array) config('cbb.season.analytics_types', self::ANALYTICS);
    }

    public static function candidates(int|string|null $type): array
    {
        if ($type === null) {
            return self::analytics();
        }

        return match (strtolower(str_replace([' ', '_', '-'], '', trim((string) $type)))) {
            '2', 'regular', 'regularseason' => self::REGULAR,
            '3', 'postseason' => self::POSTSEASON,
            default => [$type],
        };
    }
}
