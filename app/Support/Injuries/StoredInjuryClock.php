<?php

namespace App\Support\Injuries;

use Illuminate\Database\Eloquent\Model;

final class StoredInjuryClock
{
    /**
     * Legacy injury clocks have no durable timezone/observation receipt.
     *
     * @return array{source_updated_at: null, source_updated_at_raw: ?string, source_clock_status: string}
     */
    public static function fields(Model $injury): array
    {
        $raw = $injury->getRawOriginal('source_updated_at');

        return [
            'source_updated_at' => null,
            'source_updated_at_raw' => is_string($raw) ? $raw : null,
            'source_clock_status' => 'unverified_storage_clock',
        ];
    }
}
