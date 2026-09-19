<?php

namespace App\Console\Commands\Sports\Canonical;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractGenerateCanonicalPredictionsCommand extends Command
{
    public function handle(): int
    {
        $gameClass = $this->gameClass();
        $horizon = $this->generationHorizon();
        $query = $gameClass::query()->with(['sportEvent', 'homeTeam', 'awayTeam'])
            ->whereNotNull('sport_event_id')
            ->whereHas('sportEvent', function ($query) use ($horizon): void {
                $query->where('starts_at', '>=', $horizon['start'] ?? now());

                if ($horizon !== null) {
                    $query->where('starts_at', '<=', $horizon['end']);
                } elseif ($this->getDefinition()->hasOption('days-forward') && filled($this->option('days-forward'))) {
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
        if ($this->allowedSeasonTypes() !== null) {
            $query->whereIn('season_type', $this->allowedSeasonTypes());
        }
        if ($this->getDefinition()->hasOption('week') && filled($this->option('week'))) {
            $query->where('week', (int) $this->option('week'));
        }
        if ($horizon === null && filled($this->option('date'))) {
            $query->whereDate('game_date', (string) $this->option('date'));
        }
        $games = $query->get();
        if ($games->isEmpty()) {
            $this->warn('No eligible linked '.$this->sportLabel().' games matched the request.');

            return self::SUCCESS;
        }

        $this->configureBatch($games->count());
        $generator = app($this->generatorClass());
        $succeeded = 0;
        $failures = 0;
        foreach ($games as $game) {
            try {
                $this->generateGame($game, $generator);
                $succeeded++;
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("Game {$game->getKey()}: {$exception->getMessage()}");
            } finally {
                $this->releaseGameResources($game);
            }
        }
        $this->info("Canonical {$this->sportLabel()} generation complete: {$succeeded} succeeded, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function configureBatch(int $count): void {}

    protected function generateGame(Model $game, object $generator): void
    {
        $generator->execute($game, ! $this->option('draft'), 'artisan');
    }

    protected function releaseGameResources(Model $game): void {}

    /** @return class-string */
    abstract protected function gameClass(): string;

    /** @return class-string */
    abstract protected function generatorClass(): string;

    abstract protected function sportLabel(): string;

    /** @return list<string>|null */
    protected function allowedSeasonTypes(): ?array
    {
        return null;
    }

    /** @return array{start: mixed, end: mixed}|null */
    protected function generationHorizon(): ?array
    {
        return null;
    }
}
