<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\GenerateCanonicalPrediction;
use App\Models\WNBA\Game;
use Illuminate\Console\Command;

class GenerateCanonicalPredictionsCommand extends Command
{
    protected $signature = 'wnba:generate-canonical-predictions
        {--game= : Generate for one WNBA game ID}
        {--season= : Limit to a season}
        {--date= : Limit to a game date (YYYY-MM-DD)}
        {--draft : Create immutable revisions without publishing them}';

    protected $description = 'Generate canonical WNBA predictions through the snapshot and calculation-release lifecycle';

    public function handle(GenerateCanonicalPrediction $generator): int
    {
        $query = Game::query()
            ->with(['sportEvent', 'homeTeam', 'awayTeam'])
            ->whereNotNull('sport_event_id')
            ->whereHas('sportEvent', fn ($query) => $query->where('starts_at', '>=', now()))
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->orderBy('game_date')
            ->orderBy('id');

        if (filled($this->option('game'))) {
            $query->whereKey((int) $this->option('game'));
        }

        if (filled($this->option('season'))) {
            $query->where('season', (int) $this->option('season'));
        }

        if (filled($this->option('date'))) {
            $query->whereDate('game_date', (string) $this->option('date'));
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No eligible linked WNBA games matched the request.');

            return self::SUCCESS;
        }

        $published = ! $this->option('draft');
        $rows = [];
        $failures = 0;

        foreach ($games as $game) {
            try {
                $prediction = $generator->execute($game, $published, 'artisan');
                $rows[] = [
                    $game->getKey(),
                    $prediction->public_id,
                    $prediction->revision,
                    $prediction->publication_state,
                    $prediction->output_hash,
                ];
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("Game {$game->getKey()}: {$exception->getMessage()}");
            }
        }

        if ($rows !== []) {
            $this->table(['Game', 'Prediction', 'Revision', 'Publication', 'Output hash'], $rows);
        }

        $this->info(sprintf(
            'Canonical WNBA generation complete: %d succeeded, %d failed.',
            count($rows),
            $failures,
        ));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
