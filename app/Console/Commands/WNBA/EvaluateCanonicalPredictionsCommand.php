<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\EvaluateCanonicalPrediction;
use App\Models\WNBA\Game;
use Illuminate\Console\Command;

class EvaluateCanonicalPredictionsCommand extends Command
{
    protected $signature = 'wnba:evaluate-canonical-predictions
        {--game= : Evaluate one WNBA game ID}
        {--season= : Limit to a season}';

    protected $description = 'Record immutable final results and evaluate published canonical WNBA predictions';

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
                $evaluation = $evaluator->execute($game);

                if ($evaluation === null) {
                    $skipped++;
                } else {
                    $evaluated++;
                }
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("Game {$game->getKey()}: {$exception->getMessage()}");
            }
        }

        $this->info("Canonical WNBA evaluation complete: {$evaluated} evaluated, {$skipped} skipped, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
