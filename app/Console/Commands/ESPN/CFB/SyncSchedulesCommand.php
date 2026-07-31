<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Actions\ESPN\CFB\SyncGamesFromScoreboard;
use App\Console\Commands\ESPN\Concerns\IteratesDateRange;
use App\Jobs\ESPN\CFB\FetchGamesFromScoreboard;
use App\Jobs\ESPN\CFB\FetchTeamSchedule;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;
use App\Support\CfbSeasonAffiliationResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncSchedulesCommand extends Command
{
    use IteratesDateRange;

    protected $signature = 'espn:sync-cfb-schedules
        {--season= : The season year (defaults to current fall season)}
        {--team= : Specific team ESPN ID}
        {--details : Also dispatch game-details sync jobs for touched team schedules}
        {--if-empty : Skip when the selected season already has schedule rows}
        {--sync : Run synchronously instead of dispatching queued jobs}';

    protected $description = 'Sync CFB team schedules from ESPN API; defaults to all FBS teams for the selected season';

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: $this->defaultSeason());
        $teamEspnId = $this->option('team');
        $dispatchDetails = (bool) $this->option('details');
        $sync = (bool) $this->option('sync');

        if ($teamEspnId) {
            return $sync
                ? $this->runOneSynchronously((string) $teamEspnId, $season)
                : $this->dispatchOne((string) $teamEspnId, $season, $dispatchDetails);
        }

        if ($this->option('if-empty') && Game::query()->where('season', $season)->exists()) {
            $this->info("CFB schedule rows already exist for season {$season}; skipping bootstrap sync.");

            return self::SUCCESS;
        }

        $teams = $this->teamsToSync($season);
        $count = $teams->count();

        if ($count === 0) {
            $this->info("No FBS teams found for season {$season}.");

            return self::SUCCESS;
        }

        if ($this->shouldUseScoreboardFallback($teams, $season)) {
            return $sync
                ? $this->runScoreboardFallbackSynchronously($season, $dispatchDetails)
                : $this->dispatchScoreboardFallback($season, $dispatchDetails);
        }

        if ($sync) {
            return $this->runAllSynchronously($teams, $season);
        }

        $this->info("Dispatching CFB schedule sync for {$count} FBS teams (Season {$season})...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($teams as $team) {
            FetchTeamSchedule::dispatch((string) $team->espn_id, $season, $dispatchDetails)->onQueue('sync');
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Dispatched {$count} CFB team schedule sync jobs successfully.");

        return self::SUCCESS;
    }

    private function defaultSeason(): int
    {
        $now = now();

        return $now->month <= 2 ? (int) $now->year - 1 : (int) $now->year;
    }

    private function dispatchOne(string $teamEspnId, int $season, bool $dispatchDetails): int
    {
        FetchTeamSchedule::dispatch($teamEspnId, $season, $dispatchDetails)->onQueue('sync');
        $this->info("Dispatched CFB schedule sync job for team {$teamEspnId} in season {$season}.");

        return self::SUCCESS;
    }

    private function runOneSynchronously(string $teamEspnId, int $season): int
    {
        $service = new EspnService;
        $action = new SyncGamesFromSchedule($service);
        $count = $action->execute($teamEspnId, $season);

        $this->info("Synced {$count} games for team {$teamEspnId} in season {$season}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Team>  $teams
     */
    private function runAllSynchronously(Collection $teams, int $season): int
    {
        $service = new EspnService;
        $action = new SyncGamesFromSchedule($service);
        $totalGames = 0;

        $this->info("Syncing schedules for {$teams->count()} FBS teams (Season {$season})...");
        $bar = $this->output->createProgressBar($teams->count());
        $bar->start();

        foreach ($teams as $team) {
            $totalGames += $action->execute((string) $team->espn_id, $season);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Successfully synced {$totalGames} games from {$teams->count()} team schedules.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Team>  $teams
     */
    private function shouldUseScoreboardFallback(Collection $teams, int $season): bool
    {
        if ($season < (int) now()->format('Y')) {
            return false;
        }

        $service = new EspnService;
        $sampleTeamIds = $teams
            ->take(3)
            ->pluck('espn_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values();

        if ($sampleTeamIds->isEmpty()) {
            return false;
        }

        foreach ($sampleTeamIds as $teamEspnId) {
            $response = $service->getSchedule($teamEspnId, $season);
            if (count($response['events'] ?? []) > 0) {
                return false;
            }
        }

        return true;
    }

    private function dispatchScoreboardFallback(int $season, bool $dispatchDetails): int
    {
        [$startDate, $endDate] = $this->scoreboardFallbackDateRange($season);
        $totalDays = $this->inclusiveDayCount($startDate, $endDate);

        $this->warn("ESPN team schedule endpoint returned no {$season} events. Falling back to scoreboard-based schedule sync.");
        $this->info("Dispatching {$totalDays} scoreboard sync jobs ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})...");

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();

        $this->eachDateInRange($startDate, $endDate, function (Carbon $date) use ($bar): void {
            FetchGamesFromScoreboard::dispatch($date->format('Ymd'))->onQueue('sync');
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        if ($dispatchDetails) {
            $this->warn('Scoreboard fallback queued game shells only. Run espn:sync-cfb-game-details after the games are stored if you need details.');
        }

        $this->info("Dispatched {$totalDays} scoreboard sync jobs successfully.");

        return self::SUCCESS;
    }

    private function runScoreboardFallbackSynchronously(int $season, bool $dispatchDetails): int
    {
        [$startDate, $endDate] = $this->scoreboardFallbackDateRange($season);
        $totalDays = $this->inclusiveDayCount($startDate, $endDate);
        $service = new EspnService;
        $action = new SyncGamesFromScoreboard($service);
        $totalGames = 0;

        $this->warn("ESPN team schedule endpoint returned no {$season} events. Falling back to synchronous scoreboard-based schedule sync.");
        $this->info("Syncing {$totalDays} scoreboard dates ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})...");

        $bar = $this->output->createProgressBar($totalDays);
        $bar->start();

        $this->eachDateInRange($startDate, $endDate, function (Carbon $date) use ($action, &$totalGames, $bar): void {
            $totalGames += $action->execute($date->format('Ymd'));
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        if ($dispatchDetails) {
            $this->warn('Scoreboard fallback syncs games only. Run espn:sync-cfb-game-details after this if you need details.');
        }

        $this->info("Successfully synced {$totalGames} games from scoreboard fallback.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function scoreboardFallbackDateRange(int $season): array
    {
        return [
            Carbon::create($season, 8, 1)->startOfDay(),
            Carbon::create($season + 1, 1, 31)->endOfDay(),
        ];
    }

    /**
     * @return Collection<int, Team>
     */
    private function teamsToSync(int $season): Collection
    {
        /** @var CfbSeasonAffiliationResolver $resolver */
        $resolver = app(CfbSeasonAffiliationResolver::class);

        return Team::query()
            ->whereNotNull('espn_id')
            ->get()
            ->filter(fn (Team $team): bool => $resolver->isFbs($team, $season))
            ->values();
    }
}
