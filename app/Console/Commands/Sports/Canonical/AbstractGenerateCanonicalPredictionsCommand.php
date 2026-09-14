<?php

namespace App\Console\Commands\Sports\Canonical;

use Illuminate\Console\Command;

abstract class AbstractGenerateCanonicalPredictionsCommand extends Command
{
    public function handle(): int
    {
        $gameClass = $this->gameClass();
        $query = $gameClass::query()->with(['sportEvent', 'homeTeam', 'awayTeam'])
            ->whereNotNull('sport_event_id')
            ->whereHas('sportEvent', function ($query): void {
                $query->where('starts_at', '>=', now());

                if ($this->getDefinition()->hasOption('days-forward') && filled($this->option('days-forward'))) {
                    $query->where('starts_at', '<=', now()->addDays(max(1, (int) $this->option('days-forward'))));
                }
            })
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->orderBy('game_date')->orderBy('id');
        if (filled($this->option('game'))) {
            $query->whereKey((int) $this->option('game'));
        }
        if (filled($this->option('season'))) {
            $query->where('season', (int) $this->option('season'));
        }
        if ($this->getDefinition()->hasOption('week') && filled($this->option('week'))) {
            $query->where('week', (int) $this->option('week'));
        }
        if (filled($this->option('date'))) {
            $query->whereDate('game_date', (string) $this->option('date'));
        }
        $games = $query->get();
        if ($games->isEmpty()) {
            $this->warn('No eligible linked '.$this->sportLabel().' games matched the request.');

            return self::SUCCESS;
        }

        $generator = app($this->generatorClass());
        $succeeded = 0;
        $failures = 0;
        foreach ($games as $game) {
            try {
                $generator->execute($game, ! $this->option('draft'), 'artisan');
                $succeeded++;
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("Game {$game->getKey()}: {$exception->getMessage()}");
            }
        }
        $this->info("Canonical {$this->sportLabel()} generation complete: {$succeeded} succeeded, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return class-string */
    abstract protected function gameClass(): string;

    /** @return class-string */
    abstract protected function generatorClass(): string;

    abstract protected function sportLabel(): string;
}
