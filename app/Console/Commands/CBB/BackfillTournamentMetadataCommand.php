<?php

namespace App\Console\Commands\CBB;

use App\Models\CBB\Game;
use App\Services\ESPN\CBB\EspnService;
use App\Support\CbbNcaaTournamentResolver;
use Illuminate\Console\Command;

class BackfillTournamentMetadataCommand extends Command
{
    protected $signature = 'cbb:backfill-tournament-metadata
                            {--season= : Restrict to a season}
                            {--limit= : Maximum number of games to inspect}';

    protected $description = 'Backfill NCAA tournament metadata on stored CBB games using ESPN event summaries';

    public function handle(EspnService $espnService, CbbNcaaTournamentResolver $resolver): int
    {
        $query = Game::query()
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->whereNotNull('espn_event_id')
            ->where('espn_event_id', 'not like', 'placeholder:%')
            ->when($this->option('season'), fn ($q, $season) => $q->where('season', (int) $season))
            ->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No postseason CBB games found to backfill.');

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ($games as $game) {
            $eventData = $espnService->getGame((string) $game->espn_event_id);

            if (! is_array($eventData)) {
                $this->warn("Skipping game {$game->id}: ESPN event fetch failed.");
                continue;
            }

            $game->forceFill($resolver->resolveFromEspnEvent($eventData))->saveQuietly();
            $updated++;
        }

        $this->info("Backfilled tournament metadata for {$updated} CBB games.");

        return self::SUCCESS;
    }
}
