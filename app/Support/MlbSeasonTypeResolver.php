<?php

namespace App\Support;

use App\Models\MLB\Game;

class MlbSeasonTypeResolver
{
    public static function normalize(
        mixed $seasonType,
        ?int $week = null,
        mixed $gameDate = null,
        ?int $season = null
    ): string {
        $resolved = self::mapSeasonType($seasonType);

        if (
            $resolved === self::regularSeasonType()
            && $week === 1
            && ($normalizedGameDate = self::normalizeDate($gameDate)) !== null
            && ($openerDate = self::inferredRegularSeasonOpenerDate($season)) !== null
            && $normalizedGameDate < $openerDate
        ) {
            return self::springTrainingType();
        }

        return $resolved;
    }

    public static function inferredRegularSeasonOpenerDate(?int $season): ?string
    {
        if ($season === null) {
            return null;
        }

        $preferredDate = Game::query()
            ->where('season', $season)
            ->where(function ($query) {
                $query->where('season_type', self::regularSeasonType())
                    ->orWhere(function ($innerQuery) {
                        $innerQuery->where('season_type', self::legacyRegularSeasonName())
                            ->where(function ($weekQuery) {
                                $weekQuery->whereNull('week')
                                    ->orWhere('week', '!=', 1);
                            });
                    });
            })
            ->where(function ($query) {
                $query->whereNull('week')
                    ->orWhere('week', '!=', 1);
            })
            ->orderBy('game_date')
            ->value('game_date');

        if (($normalizedPreferredDate = self::normalizeDate($preferredDate)) !== null) {
            return $normalizedPreferredDate;
        }

        $fallbackDate = Game::query()
            ->where('season', $season)
            ->whereIn('season_type', [
                self::regularSeasonType(),
                self::legacyRegularSeasonName(),
            ])
            ->orderBy('game_date')
            ->value('game_date');

        return self::normalizeDate($fallbackDate);
    }

    public static function regularSeasonType(): string
    {
        return (string) config('mlb.season.types.regular', 2);
    }

    public static function springTrainingType(): string
    {
        return (string) config('mlb.season.types.spring_training', 1);
    }

    private static function mapSeasonType(mixed $seasonType): string
    {
        if (is_int($seasonType) || is_float($seasonType)) {
            return (string) (int) $seasonType;
        }

        $value = trim((string) $seasonType);

        if ($value === '') {
            return self::regularSeasonType();
        }

        if (ctype_digit($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'pre season', 'preseason', 'spring training' => self::springTrainingType(),
            'regular', 'regular season' => self::regularSeasonType(),
            'postseason', 'post season' => (string) config('mlb.season.types.postseason', 3),
            'all-star', 'all star', 'allstar' => (string) config('mlb.season.types.allstar', 4),
            default => self::regularSeasonType(),
        };
    }

    private static function legacyRegularSeasonName(): string
    {
        return 'Regular Season';
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
