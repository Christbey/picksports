<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GenerateCanonicalPrediction;
use App\Models\CBB\Game;
use App\Services\CBB\CbbPregameEligibility;
use Illuminate\Console\Command;

class GenerateCanonicalPredictionsCommand extends Command
{
    protected $signature = 'cbb:generate-canonical-predictions
        {--game= : Generate for one CBB game ID}
        {--season= : Limit to a season}
        {--date= : Limit to a game date (YYYY-MM-DD)}
        {--days-forward=8 : Bound default generation to the upcoming rollout horizon}
        {--draft : Create immutable revisions without publishing them}';

    protected $description = 'Generate canonical CBB predictions through the snapshot and calculation-release lifecycle';

    public function handle(GenerateCanonicalPrediction $generator): int
    {
        $query = Game::query()
            ->with(['sportEvent', 'homeTeam', 'awayTeam'])
            ->whereNotNull('sport_event_id')
            ->whereHas('sportEvent', fn ($query) => $query->where('starts_at', '>=', now()))
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->orderBy('game_date')
            ->orderBy('id');

        if (! filled($this->option('game')) && ! filled($this->option('date'))) {
            $end = now()->addDays(max(0, (int) $this->option('days-forward')));
            $query->whereHas('sportEvent', fn ($query) => $query->where('starts_at', '<=', $end));
        }
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
            $this->warn('No eligible linked CBB games matched the request.');

            return self::SUCCESS;
        }

        $eligibility = app(CbbPregameEligibility::class);
        foreach ($games->reject(fn ($game) => $eligibility->teamsAreKnown($game->homeTeam, $game->awayTeam)) as $held) {
            $this->warn("Game {$held->id} held: placeholder matchup requires known teams.");
        }
        $games = $games->filter(fn ($game) => $eligibility->teamsAreKnown($game->homeTeam, $game->awayTeam));
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

        $this->info("Canonical CBB generation complete: {$succeeded} succeeded, {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
