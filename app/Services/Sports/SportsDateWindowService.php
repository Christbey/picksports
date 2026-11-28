<?php

namespace App\Services\Sports;

use App\Models\MLB\Game;
use App\Support\CFB\CfbGameStart;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        if ($query->getModel() instanceof Game) {
            $table = $query->getModel()->getTable();

            return $query->where(function (Builder $rows) use ($table, $window): void {
                $rows->where(function (Builder $known) use ($table, $window): void {
                    MlbGameStart::known($known)->whereBetween("{$table}.start_epoch", [$window->utcStart->getTimestamp(), $window->utcEnd->getTimestamp()]);
                })->orWhere(function (Builder $legacy) use ($table, $window): void {
                    // Calendar inventory only: never authoritative kickoff/relative order.
                    foreach (MlbGameStart::FIELDS as $field) {
                        $legacy->whereNull("{$table}.{$field}");
                    }
                    $legacy->whereDate("{$table}.game_date", '>=', $window->localStartDate())->whereDate("{$table}.game_date", '<=', $window->localEndDate());
                });
            });
        }
        if ($query->getModel() instanceof \App\Models\CFB\Game) {
            if (! in_array($column, ['game_date', $query->getModel()->qualifyColumn('game_date')], true)) {
                throw new \LogicException('CFB date windows require the current game date column.');
            }

            return app(CfbGameCalendarRead::class)->inWindow($query, $window);
        }
        $dateColumn = $this->wrappedColumn($column);
        $timeColumn = $this->wrappedColumn($this->timeColumnFor($column));
        $combinedDateTime = $this->combinedDateTimeExpression($dateColumn, $timeColumn);

        return $query->where(function (Builder $query) use ($column, $window, $dateColumn, $timeColumn, $combinedDateTime): void {
            $query
                ->where(function (Builder $utcDateTimeQuery) use ($column, $window): void {
                    $utcDateTimeQuery
                        ->whereBetween($column, [$window->utcStartDateTime(), $window->utcEndDateTime()])
                        ->whereRaw("TIME({$column}) <> ?", ['00:00:00']);
                })
                ->orWhere(function (Builder $utcDateAndTimeQuery) use ($window, $dateColumn, $timeColumn, $combinedDateTime): void {
                    $utcDateAndTimeQuery
                        ->whereRaw("{$timeColumn} is not null")
                        ->whereRaw("{$timeColumn} <> ?", [''])
                        ->whereRaw("TIME({$dateColumn}) = ?", ['00:00:00'])
                        ->whereRaw("{$combinedDateTime} between ? and ?", [
                            $window->utcStartDateTime(),
                            $window->utcEndDateTime(),
                        ]);
                })
                ->orWhere(function (Builder $localDateQuery) use ($column, $window, $dateColumn, $timeColumn): void {
                    $localDateQuery
                        ->where(function (Builder $dateOnlyQuery) use ($timeColumn): void {
                            $dateOnlyQuery
                                ->whereRaw("{$timeColumn} is null")
                                ->orWhereRaw("{$timeColumn} = ?", ['']);
                        })
                        ->whereRaw("TIME({$dateColumn}) = ?", ['00:00:00'])
                        ->whereDate($column, '>=', $window->localStartDate())
                        ->whereDate($column, '<=', $window->localEndDate());
                });
        });
    }

    public function gameDateForDisplay(mixed $gameDate, mixed $gameTime = null): ?string
    {
        $hasTime = is_string($gameTime) && preg_match('/^\d{1,2}:\d{2}/', $gameTime) === 1;
        if (! $hasTime && $gameDate !== null && CarbonImmutable::parse($gameDate, 'UTC')->isStartOfDay()) {
            return CarbonImmutable::parse($gameDate, 'UTC')->toDateString();
        }

        $dateTime = $this->gameDateTimeUtc($gameDate, $gameTime);

        return $dateTime?->setTimezone($this->timezone())->toDateString();
    }

    public function gameStart(Model $game): ?CarbonImmutable
    {
        if ($game instanceof \App\Models\CFB\Game) {
            return CfbGameStart::for($game);
        }

        return $game instanceof Game
            ? MlbGameStart::for($game)?->toImmutable()
            : $this->gameDateTimeUtc($game->game_date, $game->game_time);
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
            return CarbonImmutable::parse($base->toDateString().' '.$gameTime, 'UTC');
        }

        return $base->setTimezone('UTC');
    }

    private function wrappedColumn(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }

    private function timeColumnFor(string $dateColumn): string
    {
        if (! str_contains($dateColumn, '.')) {
            return 'game_time';
        }

        return str($dateColumn)->beforeLast('.')->append('.game_time')->toString();
    }

    private function combinedDateTimeExpression(string $dateColumn, string $timeColumn): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "datetime(date({$dateColumn}) || ' ' || {$timeColumn})"
            : "timestamp(date({$dateColumn}), {$timeColumn})";
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
