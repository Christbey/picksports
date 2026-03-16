<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\ESPN\CFB\EspnService;
use App\Support\CfbPostseasonRoundResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BackfillPostseasonRoundsCommand extends Command
{
    protected $signature = 'cfb:backfill-postseason-rounds
        {--season=* : Limit to one or more seasons}';

    protected $description = 'Backfill normalized postseason round values for CFB games';

    public function handle(
        EspnService $espnService,
        CfbPostseasonRoundResolver $resolver,
    ): int {
        $seasons = collect((array) $this->option('season'))
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): int => (int) $value)
            ->values();

        $query = Game::query()
            ->where('season_type', (int) config('cfb.season.types.postseason'))
            ->orderBy('game_date');

        if ($seasons->isNotEmpty()) {
            $query->whereIn('season', $seasons->all());
        }

        /** @var Collection<int, Game> $games */
        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No CFB postseason games found to backfill.');

            return self::SUCCESS;
        }

        $eventsByDate = [];
        $updated = 0;

        foreach ($games as $game) {
            $scoreboardDate = $game->game_date?->format('Ymd');
            if (! $scoreboardDate) {
                continue;
            }

            if (! array_key_exists($scoreboardDate, $eventsByDate)) {
                $response = $espnService->getScoreboard($scoreboardDate);
                $events = is_array($response['events'] ?? null) ? $response['events'] : [];
                $eventsByDate[$scoreboardDate] = collect($events)->keyBy(fn (array $event): string => (string) ($event['id'] ?? ''));
            }

            /** @var Collection<string, array<string, mixed>> $scoreboardEvents */
            $scoreboardEvents = $eventsByDate[$scoreboardDate];
            $event = $scoreboardEvents->get((string) $game->espn_event_id);

            $round = is_array($event)
                ? $resolver->resolveFromEspnEvent($event)
                : $resolver->resolveFromStoredGame($game);

            if ($round === null || (int) $game->postseason_round === $round) {
                continue;
            }

            $game->forceFill(['postseason_round' => $round])->saveQuietly();
            $updated++;
        }

        $this->info("Backfilled {$updated} CFB postseason games.");

        return self::SUCCESS;
    }
}
