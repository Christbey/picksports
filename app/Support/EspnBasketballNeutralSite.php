<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/** Explicit source observations only; missing/null never means a known home venue. */
final class EspnBasketballNeutralSite
{
    private const SPORTS = ['nba', 'cbb', 'wnba', 'wcbb'];

    public static function valid(string $sport, array $payload): bool
    {
        try {
            self::reported($sport, $payload);

            return true;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    public static function reported(string $sport, array $payload): ?bool
    {
        if (! in_array($sport, self::SPORTS, true)) {
            return null;
        }
        $competition = data_get($payload, 'header.competitions.0') ?? data_get($payload, 'competitions.0', []);
        if (! is_array($competition) || ! array_key_exists('neutralSite', $competition) || $competition['neutralSite'] === null) {
            return null;
        }
        if (! is_bool($competition['neutralSite'])) {
            throw new UnexpectedValueException('Explicit basketball neutral-site observation must be boolean.');
        }

        return $competition['neutralSite'];
    }

    public static function attributes(string $sport, array $attributes, array $payload): array
    {
        if (! in_array($sport, self::SPORTS, true)) {
            return $attributes;
        }
        $reported = self::reported($sport, $payload);
        unset($attributes['neutral_site']); // Remove the legacy DTO's absent=false default.
        if ($reported !== null) {
            $attributes['neutral_site'] = $reported;
        }

        return $attributes;
    }

    public static function persist(string $sport, Model $game, array $payload): void
    {
        $reported = self::reported($sport, $payload);
        if ($reported !== null) {
            $game->setAttribute('neutral_site', $reported);
            if ($game->isDirty('neutral_site')) {
                $game->save();
            }
        }
    }
}
