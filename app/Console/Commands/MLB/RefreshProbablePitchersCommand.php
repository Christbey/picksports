<?php

namespace App\Console\Commands\MLB;

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\MLB\GeneratePrediction;
use App\Models\MLB\Game;
use App\Services\MLB\MlbStartingPitcherProjectionService;
use App\Support\SportsViewCache;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshProbablePitchersCommand extends Command
{
    protected $signature = 'mlb:refresh-probable-pitchers
        {--days-ahead=2 : Forward window in days (today + N additional days)}
        {--dry-run : Sync scoreboards and report changes without regenerating predictions}';

    protected $description = 'Pull next-N-days probable pitchers from ESPN scoreboard and regenerate predictions for games whose probable starter changed.';

    public function handle(
        SyncGamesFromScoreboard $sync,
        GeneratePrediction $generate,
        MlbStartingPitcherProjectionService $projector,
    ): int {
        $daysAhead = max(0, (int) $this->option('days-ahead'));
        $dryRun = (bool) $this->option('dry-run');

        $today = CarbonImmutable::today();
        $end = $today->addDays($daysAhead);

        $snapshot = $this->snapshotProbablePitchers($today, $end);
        $this->info(sprintf(
            'Snapshotted %d in-window scheduled games (%s through %s).',
            count($snapshot),
            $today->toDateString(),
            $end->toDateString()
        ));

        // Run scoreboard sync inline for each date so probable-pitcher updates land deterministically.
        $syncedDays = 0;
        $cursor = $today;
        while ($cursor <= $end) {
            try {
                $sync->execute($cursor->format('Ymd'));
                $syncedDays++;
            } catch (Throwable $e) {
                Log::warning('MLB probable-pitcher refresh: scoreboard sync failed.', [
                    'date' => $cursor->toDateString(),
                    'message' => $e->getMessage(),
                ]);
                $this->warn("Scoreboard sync failed for {$cursor->toDateString()}: {$e->getMessage()}");
            }
            $cursor = $cursor->addDay();
        }

        $projectionChanges = 0;
        Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('status', 'STATUS_SCHEDULED')
            ->whereBetween('game_date', [$today->startOfDay(), $end->endOfDay()])
            ->each(function (Game $game) use ($projector, &$projectionChanges): void {
                try {
                    if ($projector->project($game)['changed']) {
                        $projectionChanges++;
                    }
                } catch (Throwable $e) {
                    Log::warning('MLB probable-pitcher refresh: rotation projection failed.', [
                        'game_id' => $game->id,
                        'message' => $e->getMessage(),
                    ]);
                    $this->warn("Projection failed for game {$game->id}: {$e->getMessage()}");
                }
            });

        $changed = $this->detectChangedGames($today, $end, $snapshot);

        if ($changed->isEmpty()) {
            $this->info("Synced {$syncedDays} scoreboard day(s); no probable-pitcher changes detected ({$projectionChanges} projection updates).");

            return self::SUCCESS;
        }

        $this->info("Synced {$syncedDays} scoreboard day(s); {$changed->count()} game(s) had probable-pitcher changes ({$projectionChanges} projection updates).");

        if ($dryRun) {
            foreach ($changed as $entry) {
                $this->line(sprintf(
                    '  [dry-run] game_id=%d home %s/%s -> %s/%s, away %s/%s -> %s/%s',
                    $entry['game']->id,
                    $entry['previous']['home_source'] ?? 'null',
                    $entry['previous']['home'] ?? 'null',
                    $entry['game']->startingPitcherSource('home') ?? 'null',
                    $entry['game']->resolvedStartingPitcherEspnId('home') ?? 'null',
                    $entry['previous']['away_source'] ?? 'null',
                    $entry['previous']['away'] ?? 'null',
                    $entry['game']->startingPitcherSource('away') ?? 'null',
                    $entry['game']->resolvedStartingPitcherEspnId('away') ?? 'null',
                ));
            }

            return self::SUCCESS;
        }

        $regenerated = 0;
        foreach ($changed as $entry) {
            $game = $entry['game']->fresh(['homeTeam', 'awayTeam']);
            if (! $game) {
                continue;
            }
            try {
                if ($generate->execute($game)) {
                    $regenerated++;
                }
            } catch (Throwable $e) {
                Log::warning('MLB probable-pitcher refresh: prediction regen failed.', [
                    'game_id' => $game->id,
                    'message' => $e->getMessage(),
                ]);
                $this->warn("Regen failed for game {$game->id}: {$e->getMessage()}");
            }
        }

        if ($regenerated > 0) {
            app(SportsViewCache::class)->bustSegments([
                SportsViewCache::SEGMENT_DASHBOARD,
                SportsViewCache::SEGMENT_LIVE_SCOREBOARD,
                SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
                SportsViewCache::SEGMENT_PREDICTIONS_BY_GAME,
                SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_DATES,
                SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_SEASONS,
            ]);
        }

        $this->info("Regenerated predictions for {$regenerated} game(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{home: ?string, away: ?string, home_source: ?string, away_source: ?string, home_confidence: ?float, away_confidence: ?float}>
     */
    private function snapshotProbablePitchers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Game::query()
            ->where('status', 'STATUS_SCHEDULED')
            ->whereBetween('game_date', [$from->startOfDay(), $to->endOfDay()])
            ->get()
            ->mapWithKeys(fn (Game $game) => [
                $game->id => [
                    'home' => $game->resolvedStartingPitcherEspnId('home'),
                    'away' => $game->resolvedStartingPitcherEspnId('away'),
                    'home_source' => $game->startingPitcherSource('home'),
                    'away_source' => $game->startingPitcherSource('away'),
                    'home_confidence' => $game->startingPitcherConfidence('home'),
                    'away_confidence' => $game->startingPitcherConfidence('away'),
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{home: ?string, away: ?string, home_source: ?string, away_source: ?string, home_confidence: ?float, away_confidence: ?float}>  $snapshot
     * @return Collection<int, array{game: Game, previous: array<string, mixed>}>
     */
    private function detectChangedGames(CarbonImmutable $from, CarbonImmutable $to, array $snapshot): Collection
    {
        return Game::query()
            ->where('status', 'STATUS_SCHEDULED')
            ->whereBetween('game_date', [$from->startOfDay(), $to->endOfDay()])
            ->get()
            ->filter(function (Game $game) use ($snapshot) {
                $previous = $snapshot[$game->id] ?? [];
                $homeChanged = ($previous['home'] ?? null) !== $game->resolvedStartingPitcherEspnId('home')
                    || ($previous['home_source'] ?? null) !== $game->startingPitcherSource('home')
                    || $this->confidenceChanged($previous['home_confidence'] ?? null, $game->startingPitcherConfidence('home'));
                $awayChanged = ($previous['away'] ?? null) !== $game->resolvedStartingPitcherEspnId('away')
                    || ($previous['away_source'] ?? null) !== $game->startingPitcherSource('away')
                    || $this->confidenceChanged($previous['away_confidence'] ?? null, $game->startingPitcherConfidence('away'));

                return $homeChanged || $awayChanged;
            })
            ->map(fn (Game $game) => [
                'game' => $game,
                'previous' => $snapshot[$game->id] ?? ['home' => null, 'away' => null],
            ])
            ->values();
    }

    private function confidenceChanged(mixed $before, ?float $after): bool
    {
        if ($before === null || $after === null) {
            return $before !== $after;
        }

        return abs((float) $before - $after) > 0.00005;
    }
}
