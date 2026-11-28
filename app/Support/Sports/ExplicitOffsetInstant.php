<?php

namespace App\Support\Sports;

use Carbon\CarbonImmutable;
use Throwable;

/** Versioned producer timestamps require an explicit offset and a real calendar; never relative text or now. */
final class ExplicitOffsetInstant
{
    public static function parse(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || strlen($raw) > 64
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})$/D', $raw, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }
        $offset = $parts[8];
        if ($offset === '-00:00' || ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59))) {
            return null;
        }
        $fraction = str_pad($parts[7] ?? '', 6, '0');
        $normalized = sprintf('%s-%s-%sT%s:%s:%s.%s%s', $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $parts[6], $fraction, $offset === 'Z' ? '+00:00' : $offset);
        try {
            $instant = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
            $errors = CarbonImmutable::getLastErrors();
            if ($instant === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
                return null;
            }

            return $instant->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
