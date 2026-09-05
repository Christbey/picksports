<?php

namespace App\Console\Commands\Sports\Canonical;

use Illuminate\Console\Command;

abstract class AbstractEvaluateCanonicalPredictionsCommand extends Command
{
    public function handle(): int
    {
        $gameClass = $this->gameClass();
        $query = $gameClass::query()->with('sportEvent')->whereNotNull('sport_event_id')
            ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
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
        $evaluator = app($this->evaluatorClass());
        $evaluated = 0;
        $skipped = 0;
        $failures = 0;
        foreach ($query->get() as $game) {
            try {
                $evaluator->execute($game) === null ? $skipped++ : $evaluated++;
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("Game {$game->getKey()}: {$exception->getMessage()}");
            }
        }
        $this->info("Canonical {$this->sportLabel()} evaluation complete: {$evaluated} evaluated, {$skipped} skipped, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return class-string */
    abstract protected function gameClass(): string;

    /** @return class-string */
    abstract protected function evaluatorClass(): string;

    abstract protected function sportLabel(): string;
}
