<?php

namespace App\Services\Sports;

use Carbon\CarbonImmutable;

class DateWindow
{
    public function __construct(
        public readonly CarbonImmutable $localStart,
        public readonly CarbonImmutable $localEnd,
        public readonly CarbonImmutable $utcStart,
        public readonly CarbonImmutable $utcEnd,
        public readonly string $timezone,
    ) {}

    public function localStartDate(): string
    {
        return $this->localStart->toDateString();
    }

    public function localEndDate(): string
    {
        return $this->localEnd->toDateString();
    }

    public function utcStartDateTime(): string
    {
        return $this->utcStart->toDateTimeString();
    }

    public function utcEndDateTime(): string
    {
        return $this->utcEnd->toDateTimeString();
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'local_start' => $this->localStart->toIso8601String(),
            'local_end' => $this->localEnd->toIso8601String(),
            'local_start_date' => $this->localStartDate(),
            'local_end_date' => $this->localEndDate(),
            'utc_start' => $this->utcStart->toIso8601String(),
            'utc_end' => $this->utcEnd->toIso8601String(),
        ];
    }
}
