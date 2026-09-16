<?php

namespace App\Services\NFL\Predictions;

use Carbon\CarbonImmutable;

class NflPregameHorizon
{
    /** @return list<string> */
    public static function seasonTypes(): array
    {
        return array_values(array_unique([
            (string) config('nfl.season.types.regular', 2), '2', 'regular', 'Regular Season',
            (string) config('nfl.season.types.postseason', 3), '3', 'postseason', 'Postseason',
        ]));
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable, date: string, days_forward: int} */
    public function resolve(?string $date = null, int $daysForward = 8): array
    {
        $daysForward = max(1, $daysForward);
        $now = now()->toImmutable();
        $start = $now;

        if (filled($date)) {
            $requestedStart = CarbonImmutable::parse(
                $date,
                (string) config('sports.business_timezone', config('app.timezone')),
            )->startOfDay()->utc();
            $start = $requestedStart->greaterThan($now) ? $requestedStart : $now;
        }

        return [
            'start' => $start,
            'end' => $start->addDays($daysForward),
            'date' => $start
                ->setTimezone((string) config('sports.business_timezone', config('app.timezone')))
                ->toDateString(),
            'days_forward' => $daysForward,
        ];
    }
}
