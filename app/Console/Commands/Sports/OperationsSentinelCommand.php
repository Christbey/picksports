<?php

namespace App\Console\Commands\Sports;

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use App\Services\ESPN\NBA\EspnService;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsDateWindowService;
use App\Services\Sports\SportsPipelineRegistry;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class OperationsSentinelCommand extends Command
{
    private const LOCK_SECONDS = 7200;

    protected $signature = 'sports:operations-sentinel
        {--sport= : Sport to run: nba, nfl, mlb, cbb, cfb, wcbb, wnba}
        {--from-date= : Start date in YYYY-MM-DD format}
        {--to-date= : End date in YYYY-MM-DD format}
        {--season= : Season to repair/grade}
        {--skip-sync-pipeline : Skip registry sync dependencies such as injuries, weather, odds, player props, and futures}
        {--skip-stats : Skip player/team stat refresh}
        {--skip-model-pipeline : Skip grading, Elo, team metrics, and prediction generation}
        {--stat-lookback-days=3 : Days back to refresh player/team stats from game details}
        {--stat-limit=100 : Limit game-detail stat refresh dispatches per sentinel run}
        {--skip-validation : Skip the final validation run}';

    protected $description = 'Run a sport operations sentinel without duplicating sport-specific repair logic';

    /**
     * @var array<string, class-string>
     */
    private array $scoreboardSyncActions = [
        'nba' => SyncGamesFromScoreboard::class,
        'nfl' => \App\Actions\ESPN\NFL\SyncGamesFromScoreboard::class,
        'mlb' => \App\Actions\ESPN\MLB\SyncGamesFromScoreboard::class,
        'cbb' => \App\Actions\ESPN\CBB\SyncGamesFromScoreboard::class,
        'cfb' => \App\Actions\ESPN\CFB\SyncGamesFromScoreboard::class,
        'wcbb' => \App\Actions\ESPN\WCBB\SyncGamesFromScoreboard::class,
        'wnba' => \App\Actions\ESPN\WNBA\SyncGamesFromScoreboard::class,
    ];

    /**
     * @var array<string, class-string>
     */
    private array $scoreboardEspnServices = [
        'nba' => EspnService::class,
        'nfl' => \App\Services\ESPN\NFL\EspnService::class,
        'mlb' => \App\Services\ESPN\MLB\EspnService::class,
        'cbb' => \App\Services\ESPN\CBB\EspnService::class,
        'cfb' => \App\Services\ESPN\CFB\EspnService::class,
        'wcbb' => \App\Services\ESPN\WCBB\EspnService::class,
        'wnba' => \App\Services\ESPN\WNBA\EspnService::class,
    ];

    /**
     * @var array<string, string>
     */
    private array $gameDetailsCommands = [
        'nba' => 'espn:sync-nba-game-details',
        'nfl' => 'espn:sync-nfl-game-details',
        'mlb' => 'espn:sync-mlb-game-details',
        'cbb' => 'espn:sync-cbb-game-details',
        'cfb' => 'espn:sync-cfb-game-details',
        'wcbb' => 'espn:sync-wcbb-game-details',
        'wnba' => 'espn:sync-wnba-game-details',
    ];

    public function handle(SportsPipelineRegistry $registry): int
    {
        $lock = Cache::lock('sports:operations-sentinel', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->warn('Another sports operations sentinel is already running. Skipping this run to avoid overlapping stat and metric repairs.');

            return self::FAILURE;
        }

        try {
            return $this->runSentinel($registry);
        } finally {
            $lock->release();
        }
    }

    private function runSentinel(SportsPipelineRegistry $registry): int
    {
        $sport = strtolower((string) $this->option('sport'));

        if ($sport === '') {
            $this->error('The --sport option is required.');

            return self::FAILURE;
        }

        if (! $registry->supportsSport($sport)) {
            $this->error("Unsupported sport: {$sport}.");

            return self::FAILURE;
        }

        $syncClass = $this->scoreboardSyncActions[$sport] ?? null;

        if (! $syncClass) {
            $this->error("No operations sentinel is configured for {$sport} yet.");

            return self::FAILURE;
        }

        $dateWindows = app(SportsDateWindowService::class);
        $fromDate = $dateWindows->parseLocalDate($this->option('from-date') ?: now()->subDay()->toDateString());
        $toDate = $dateWindows->parseLocalDate($this->option('to-date') ?: now()->addDays(7)->toDateString());
        $season = (int) ($this->option('season') ?: $this->defaultSeason($sport));
        $statLookbackDays = max(1, (int) $this->option('stat-lookback-days'));
        $statLimit = max(0, (int) $this->option('stat-limit'));

        if ($toDate->lt($fromDate)) {
            $this->error('--to-date must be on or after --from-date.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Running %s operations sentinel for %s through %s (%s).',
            strtoupper($sport),
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $dateWindows->timezone(),
        ));

        $stageContext = app(SeasonStageService::class)->context($sport, $season, $fromDate);
        $this->line(sprintf(
            'Season stage: %s (%s); active window %s through %s.',
            $stageContext->stage,
            $stageContext->stageGroup,
            $stageContext->activeWindow->localStartDate(),
            $stageContext->activeWindow->localEndDate(),
        ));

        $sync = $this->scoreboardSyncAction($sport, $syncClass);
        $synced = 0;

        for ($date = $fromDate; $date->lte($toDate); $date = $date->addDay()) {
            $scoreboardDate = $date->format('Ymd');
            $this->line('Syncing '.strtoupper($sport)." scoreboard {$scoreboardDate}...");
            $synced += (int) $sync->execute($scoreboardDate);
        }

        $this->info('Synced '.$synced.' '.strtoupper($sport).' game row update(s).');

        if (! $this->option('skip-stats')) {
            $this->refreshStats($sport, $statLookbackDays, $statLimit);
        }

        if (! $this->option('skip-sync-pipeline')) {
            $this->runSyncDependencyPipeline($registry, $sport, $season, $fromDate);
        }

        if (! $this->option('skip-model-pipeline')) {
            $this->runModelPipeline($registry, $sport, $season, $fromDate);
        }

        if ($this->option('skip-validation')) {
            return self::SUCCESS;
        }

        $this->info('Running '.strtoupper($sport).' validation after sentinel repair pass...');

        return $this->call('healthcheck:validate-data', [
            '--sport' => $sport,
        ]);
    }

    /**
     * @param  class-string  $syncClass
     */
    private function scoreboardSyncAction(string $sport, string $syncClass): object
    {
        try {
            return app($syncClass);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'ESPN sport key must be provided.') {
                throw $exception;
            }

            $serviceClass = $this->scoreboardEspnServices[$sport] ?? null;

            if (! $serviceClass) {
                throw $exception;
            }

            return new $syncClass(new $serviceClass);
        }
    }

    private function refreshStats(string $sport, int $lookbackDays, int $limit): void
    {
        $gameDetailsCommand = $this->gameDetailsCommands[$sport] ?? null;

        if (! $gameDetailsCommand) {
            $this->warn('Player/team stat refresh is not configured for '.strtoupper($sport).'.');

            return;
        }

        $this->info(sprintf(
            'Refreshing %s player/team stats from game details for the last %d day(s).',
            strtoupper($sport),
            $lookbackDays,
        ));

        $this->call($gameDetailsCommand, [
            '--refresh-existing' => true,
            '--lookback-days' => $lookbackDays,
            '--latest' => true,
            '--limit' => $limit,
        ]);
    }

    private function runModelPipeline(SportsPipelineRegistry $registry, string $sport, int $season, CarbonInterface $referenceDate): void
    {
        $this->info('Running '.strtoupper($sport).' canonical model pipeline...');

        foreach ($this->registrySteps($registry, $sport, 'predict', $season, $referenceDate) as $step) {
            $this->callRegistryStep($step);
        }
    }

    private function runSyncDependencyPipeline(SportsPipelineRegistry $registry, string $sport, int $season, CarbonInterface $referenceDate): void
    {
        $this->info('Running '.strtoupper($sport).' canonical sync dependencies...');

        foreach ($this->registrySteps($registry, $sport, 'sync', $season, $referenceDate) as $step) {
            if ($this->isAlreadyHandledSyncStep($step['label'])) {
                continue;
            }

            $this->callRegistryStep($step);
        }
    }

    /**
     * @return array<int, array{label: string, command: string, arguments: array<string, mixed>}>
     */
    private function registrySteps(SportsPipelineRegistry $registry, string $sport, string $mode, int $season, CarbonInterface $referenceDate): array
    {
        return $registry->pipelineSteps($sport, $mode, $registry->context(
            $referenceDate->toDateString(),
            $season,
        ));
    }

    /**
     * @param  array{label: string, command: string, arguments: array<string, mixed>}  $step
     */
    private function callRegistryStep(array $step): void
    {
        $this->info("Running {$step['label']}...");
        Artisan::call($step['command'], $step['arguments']);
        $this->output->write(Artisan::output());
    }

    private function isAlreadyHandledSyncStep(string $label): bool
    {
        return str_contains(strtolower($label), 'scoreboard')
            || str_contains(strtolower($label), 'current')
            || str_contains(strtolower($label), 'schedules')
            || str_contains(strtolower($label), 'game details');
    }

    private function defaultSeason(string $sport): int
    {
        $currentYear = (int) now()->year;

        return match ($sport) {
            'nfl', 'cfb', 'cbb', 'wcbb' => now()->month <= 2 ? $currentYear - 1 : $currentYear,
            default => $currentYear,
        };
    }
}
