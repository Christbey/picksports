<?php

namespace App\Support\ESPN;

use InvalidArgumentException;

final class PlayIdentity
{
    /** All seven legacy espn_play_id columns are VARCHAR(50); preserve opaque bytes/case. */
    public static function from(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Play identities require a bounded observed string or integer.');
        }
        $id = (string) $value;
        if (strlen($id) > 200 || ! mb_check_encoding($id, 'UTF-8') || mb_strlen($id, 'UTF-8') > 50
            || preg_match('/[\p{Cc}]/u', $id) !== 0) {
            throw new InvalidArgumentException('Play identities exceed the stored capacity or contain control characters.');
        }

        return $id;
    }
}
