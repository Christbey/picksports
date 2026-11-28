<?php

namespace App\Support\ESPN;

final class PlayTeamReferences
{
    /** Consumed identity references only; no reference URL is fetched. */
    public static function fromPlay(array $raw): array
    {
        $ids = [];
        foreach (['team', 'pitchingTeam', 'start.team', 'end.team'] as $path) {
            $reference = data_get($raw, $path);
            if ($reference !== null) {
                $ids = [...$ids, ...self::referenceIds($reference)];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Present references cannot silently become an unattributed play. No URL is fetched. */
    private static function referenceIds(mixed $reference): array
    {
        $ids = [];
        if (is_array($reference)) {
            if (isset($reference['id']) && $reference['id'] !== '') {
                if (! is_int($reference['id']) && ! is_string($reference['id'])) {
                    throw new \RuntimeException('Invalid play team identity; preserving stored plays.');
                }
                $ids[] = (string) $reference['id'];
            }
            if (isset($reference['$ref']) && $reference['$ref'] !== '') {
                $ids = [...$ids, ...self::referenceIds($reference['$ref'])];
            }
        } elseif (is_int($reference) || is_string($reference)) {
            $reference = (string) $reference;
            if (ctype_digit($reference)) {
                $ids[] = $reference;
            } elseif (preg_match('~/teams/([A-Za-z0-9_-]+)/?$~D', (string) parse_url($reference, PHP_URL_PATH), $matches) === 1) {
                $ids[] = $matches[1];
            }
        }
        if ($ids === []) {
            throw new \RuntimeException('Unresolvable play team reference; preserving stored plays.');
        }

        $ids = array_values(array_unique($ids));
        if (count($ids) !== 1) {
            throw new \RuntimeException('Conflicting play team references; preserving stored plays.');
        }

        return $ids;
    }
}
