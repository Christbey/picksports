<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Services\ESPN\MLB\EspnService;
use App\Support\MLB\MlbLineScores;
use Illuminate\Support\Carbon;

class MlbLineScoreBackfillService
{
    public function __construct(private readonly EspnService $espn) {}

    /**
     * @return array{games:int,dates:int,updated:int,unmatched:int,failed_dates:int,event_fallbacks:int}
     */
    public function backfill(
        int $season,
        ?string $fromDate = null,
        ?string $toDate = null,
        bool $dryRun = false,
        int $sleepMilliseconds = 100,
    ): array {
        $games = Game::query()
            ->where('season', $season)
            ->where('season_type', config('mlb.season.types.regular', 2))
            ->where('status', config('mlb.statuses.final', 'STATUS_FINAL'))
            ->whereNotNull('espn_event_id')
            ->where(function ($query) {
                $query
                    ->whereNull('home_linescores')
                    ->orWhereNull('away_linescores');
            })
            ->when($fromDate, fn ($query) => $query->whereDate('game_date', '>=', Carbon::parse($fromDate)->toDateString()))
            ->when($toDate, fn ($query) => $query->whereDate('game_date', '<=', Carbon::parse($toDate)->toDateString()))
            ->orderBy('game_date')
            ->get();

        $gamesByDate = $games->groupBy(fn (Game $game): string => $game->game_date->toDateString());
        $updated = 0;
        $unmatched = 0;
        $failedDates = 0;
        $eventFallbacks = 0;

        foreach ($gamesByDate as $date => $dateGames) {
            $response = $this->espn->getScoreboard(Carbon::parse($date)->format('Ymd'));
            if (! is_array($response)) {
                $failedDates++;
            }

            $events = collect((array) data_get($response, 'events', []))
                ->filter(fn (mixed $event): bool => is_array($event) && is_scalar($event['id'] ?? null))
                ->keyBy(fn (array $event): string => (string) $event['id']);

            foreach ($dateGames as $game) {
                $event = $events->get((string) $game->espn_event_id);
                $competitors = is_array($event)
                    ? (array) data_get($event, 'competitions.0.competitors', [])
                    : [];
                $updates = $this->lineScoreUpdates($game, $competitors);

                if ($updates === []) {
                    $eventFallbacks++;
                    $summary = $this->espn->getGame((string) $game->espn_event_id);
                    $competitors = (array) data_get($summary, 'header.competitions.0.competitors', []);
                    $updates = $this->lineScoreUpdates($game, $competitors);
                }

                if ($updates === []) {
                    $unmatched++;

                    continue;
                }

                if (! $dryRun) {
                    $game->update($updates);
                }
                $updated++;
            }

            if ($sleepMilliseconds > 0) {
                usleep($sleepMilliseconds * 1000);
            }
        }

        return [
            'games' => $games->count(),
            'dates' => $gamesByDate->count(),
            'updated' => $updated,
            'unmatched' => $unmatched,
            'failed_dates' => $failedDates,
            'event_fallbacks' => $eventFallbacks,
        ];
    }

    /**
     * @param  array<int, mixed>  $competitors
     * @return array<string, list<mixed>>
     */
    private function lineScoreUpdates(Game $game, array $competitors): array
    {
        $competitors = collect($competitors);
        $home = $competitors->firstWhere('homeAway', 'home');
        $away = $competitors->firstWhere('homeAway', 'away');
        $updates = [];

        if ($game->home_linescores === null) {
            $lineScores = MlbLineScores::normalize(data_get($home, 'linescores'));
            if ($lineScores !== []) {
                $updates['home_linescores'] = $lineScores;
            }
        }
        if ($game->away_linescores === null) {
            $lineScores = MlbLineScores::normalize(data_get($away, 'linescores'));
            if ($lineScores !== []) {
                $updates['away_linescores'] = $lineScores;
            }
        }

        return $updates;
    }
}
