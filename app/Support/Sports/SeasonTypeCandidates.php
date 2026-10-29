<?php

namespace App\Support\Sports;

class SeasonTypeCandidates
{
    /**
     * @return array<int, int|string>
     */
    public static function resolve(string $sportSlug, mixed $seasonType): array
    {
        if ($seasonType === null || $seasonType === '') {
            return [];
        }

        $typeNames = config("{$sportSlug}.season.type_names", []);
        $typesByKey = config("{$sportSlug}.season.types", []);
        $candidates = [$seasonType, (string) $seasonType];

        if (is_string($seasonType) && isset($typeNames[$seasonType])) {
            $candidates[] = $typeNames[$seasonType];
        }

        if (is_string($seasonType) && isset($typesByKey[$seasonType])) {
            $resolved = $typesByKey[$seasonType];
            $candidates[] = $resolved;
            $candidates[] = (string) $resolved;
        }

        if (is_numeric($seasonType)) {
            $code = (int) $seasonType;
            $matchedKey = array_search($code, $typesByKey, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = $typeNames[$matchedKey];
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => $value !== null && $value !== ''
        )));
    }
}
