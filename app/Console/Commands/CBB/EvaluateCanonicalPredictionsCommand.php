<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\EvaluateCanonicalPrediction;
use App\Models\CBB\Game;
use Illuminate\Console\Command;

class EvaluateCanonicalPredictionsCommand extends Command
{
    protected $signature = 'cbb:evaluate-canonical-predictions
        {--game= : Evaluate one CBB game ID}
        {--season= : Limit to a season}';

    protected $description = 'Record immutable final results and evaluate published canonical CBB predictions';

    public function handle(EvaluateCanonicalPrediction $evaluator): int
    {
        $query = Game::query()
            ->with('sportEvent')
            ->whereNotNull('sport_event_id')
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderBy('game_date')
            ->orderBy('id');

        if (filled($this->option('game'))) {
            $query->whereKey((int) $this->option('game'));
        }
        if (filled($this->option('season'))) {
            $query->where('season', (int) $this->option('season'));
        }

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

        $this->info("Canonical CBB evaluation complete: {$evaluated} evaluated, {$skipped} skipped, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
