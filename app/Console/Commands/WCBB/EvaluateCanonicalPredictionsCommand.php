<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\EvaluateCanonicalPrediction;
use App\Models\WCBB\Game;
use Illuminate\Console\Command;

class EvaluateCanonicalPredictionsCommand extends Command
{
    protected $signature = 'wcbb:evaluate-canonical-predictions
        {--game= : Evaluate one WCBB game ID}
        {--season= : Limit to a season}';

    protected $description = 'Record immutable final results and evaluate published canonical WCBB predictions';

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

        $this->info("Canonical WCBB evaluation complete: {$evaluated} evaluated, {$skipped} skipped, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
