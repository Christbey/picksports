<?php

namespace App\Services\Sports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class SportsDateWindowService
{
    public function timezone(): string
    {
        return (string) config('sports.business_timezone', config('app.timezone', 'UTC'));
    }

    public function parseLocalDate(string|CarbonInterface|null $date = null): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->setTimezone($this->timezone())->startOfDay();
        }

        if (is_string($date) && trim($date) !== '') {
            return CarbonImmutable::parse($date, $this->timezone())->startOfDay();
        }

        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    public function forDate(string|CarbonInterface|null $date = null): DateWindow
    {
        $start = $this->parseLocalDate($date);

        return $this->fromLocalRange($start, $start->endOfDay());
    }

    public function forRange(string|CarbonInterface|null $fromDate, string|CarbonInterface|null $toDate): DateWindow
    {
        $start = $this->parseLocalDate($fromDate);
        $end = $this->parseLocalDate($toDate)->endOfDay();

        return $this->fromLocalRange($start, $end);
    }

    public function forwardWindow(string|CarbonInterface|null $date = null, int $daysForward = 7): DateWindow
    {
        $start = $this->parseLocalDate($date);
        $end = $start->addDays(max(0, $daysForward))->endOfDay();

        return $this->fromLocalRange($start, $end);
    }

    public function weekWindow(string|CarbonInterface|null $date = null): DateWindow
    {
        $reference = $this->parseLocalDate($date);

        return $this->fromLocalRange($reference->startOfWeek(), $reference->endOfWeek());
    }

    public function monthWindow(string|CarbonInterface|null $date = null): DateWindow
    {
        $reference = $this->parseLocalDate($date);

        return $this->fromLocalRange($reference->startOfMonth(), $reference->endOfMonth());
    }

    public function applyGameDateWindow(Builder $query, DateWindow $window, string $column = 'game_date'): Builder
    {
        return $query->where(function (Builder $query) use ($column, $window): void {
            $query
                ->whereBetween($column, [$window->utcStartDateTime(), $window->utcEndDateTime()])
                ->orWhere(function (Builder $localDateQuery) use ($column, $window): void {
                    $localDateQuery
                        ->whereDate($column, '>=', $window->localStartDate())
                        ->whereDate($column, '<=', $window->localEndDate());
                });
        });
    }

    public function gameDateForDisplay(mixed $gameDate, mixed $gameTime = null): ?string
    {
        if ($this->isDateOnly($gameDate) && ! (is_string($gameTime) && preg_match('/^\d{1,2}:\d{2}/', $gameTime) === 1)) {
            return CarbonImmutable::parse((string) $gameDate, $this->timezone())->toDateString();
        }

        $dateTime = $this->gameDateTimeUtc($gameDate, $gameTime);

        return $dateTime?->setTimezone($this->timezone())->toDateString();
    }

    public function gameDateTimeUtc(mixed $gameDate, mixed $gameTime = null): ?CarbonImmutable
    {
        if ($gameDate instanceof CarbonInterface) {
            $base = CarbonImmutable::instance($gameDate);
        } elseif (is_string($gameDate) && trim($gameDate) !== '') {
            $base = CarbonImmutable::parse($gameDate, 'UTC');
        } else {
            return null;
        }

        if (is_string($gameTime) && preg_match('/^\d{1,2}:\d{2}/', $gameTime) === 1) {
            return CarbonImmutable::parse($base->toDateString().' '.$gameTime, $this->timezone())->utc();
        }

        return $base->setTimezone('UTC');
    }

    private function isDateOnly(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    private function fromLocalRange(CarbonImmutable $start, CarbonImmutable $end): DateWindow
    {
        $timezone = $this->timezone();

        return new DateWindow(
            localStart: $start->setTimezone($timezone),
            localEnd: $end->setTimezone($timezone),
            utcStart: $start->setTimezone('UTC'),
            utcEnd: $end->setTimezone('UTC'),
            timezone: $timezone,
        );
    }
}
