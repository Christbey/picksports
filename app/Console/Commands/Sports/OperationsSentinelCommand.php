<?php

namespace App\Console\Commands\Sports;

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use App\Actions\NBA\GradePredictions;
use App\Services\Sports\SportsPipelineRegistry;
use Carbon\CarbonImmutable;
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
        {--skip-stats : Skip player/team stat refresh}
        {--skip-model-pipeline : Skip grading, Elo, team metrics, and prediction generation}
        {--stat-lookback-days=3 : Days back to refresh player/team stats from game details}
        {--stat-limit=25 : Limit game-detail stat refresh dispatches per sentinel run}
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
    private array $gradePredictionActions = [
        'nba' => GradePredictions::class,
        'nfl' => \App\Actions\NFL\GradePredictions::class,
        'mlb' => \App\Actions\MLB\GradePredictions::class,
        'cbb' => \App\Actions\CBB\GradePredictions::class,
        'cfb' => \App\Actions\CFB\GradePredictions::class,
        'wcbb' => \App\Actions\WCBB\GradePredictions::class,
        'wnba' => \App\Actions\WNBA\GradePredictions::class,
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

    /**
     * @var array<string, string>
     */
    private array $eloCommands = [
        'nba' => 'nba:calculate-elo',
        'nfl' => 'nfl:calculate-elo',
        'mlb' => 'mlb:calculate-elo',
        'cbb' => 'cbb:calculate-elo',
        'cfb' => 'cfb:calculate-elo',
        'wcbb' => 'wcbb:calculate-elo',
        'wnba' => 'wnba:calculate-elo',
    ];

    /**
     * @var array<string, string>
     */
    private array $teamMetricCommands = [
        'nba' => 'nba:calculate-team-metrics',
        'nfl' => 'nfl:calculate-team-metrics',
        'mlb' => 'mlb:calculate-team-metrics',
        'cbb' => 'cbb:calculate-team-metrics',
        'cfb' => 'cfb:calculate-team-metrics',
        'wcbb' => 'wcbb:calculate-team-metrics',
        'wnba' => 'wnba:calculate-team-metrics',
    ];

    /**
     * @var array<string, string>
     */
    private array $generatePredictionCommands = [
        'nba' => 'nba:generate-predictions',
        'nfl' => 'nfl:generate-predictions',
        'mlb' => 'mlb:generate-predictions',
        'cbb' => 'cbb:generate-predictions',
        'cfb' => 'cfb:generate-predictions',
        'wcbb' => 'wcbb:generate-predictions',
        'wnba' => 'wnba:generate-predictions',
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
        $gradeClass = $this->gradePredictionActions[$sport] ?? null;

        if (! $syncClass || ! $gradeClass) {
            $this->error("No operations sentinel is configured for {$sport} yet.");

            return self::FAILURE;
        }

        $fromDate = CarbonImmutable::parse($this->option('from-date') ?: now()->subDay()->toDateString())->startOfDay();
        $toDate = CarbonImmutable::parse($this->option('to-date') ?: now()->addDay()->toDateString())->startOfDay();
        $season = (int) ($this->option('season') ?: $this->defaultSeason($sport));
        $statLookbackDays = max(1, (int) $this->option('stat-lookback-days'));
        $statLimit = max(0, (int) $this->option('stat-limit'));

        if ($toDate->lt($fromDate)) {
            $this->error('--to-date must be on or after --from-date.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Running %s operations sentinel for %s through %s.',
            strtoupper($sport),
            $fromDate->toDateString(),
            $toDate->toDateString(),
        ));

        $sync = app($syncClass);
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

        if (! $this->option('skip-model-pipeline')) {
            $this->runModelPipeline($sport, $season, $gradeClass);
        }

        if ($this->option('skip-validation')) {
            return self::SUCCESS;
        }

        $this->info('Running '.strtoupper($sport).' validation after sentinel repair pass...');

        return $this->call('healthcheck:validate-data', [
            '--sport' => $sport,
        ]);
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

    /**
     * @param  class-string  $gradeClass
     */
    private function runModelPipeline(string $sport, int $season, string $gradeClass): void
    {
        $grading = app($gradeClass)->execute($season);
        $this->info(sprintf(
            'Graded %d %s prediction(s) for season %d.',
            (int) ($grading['graded'] ?? 0),
            strtoupper($sport),
            $season,
        ));

        $this->callPipelineCommand($this->eloCommands[$sport] ?? null, $season, strtoupper($sport).' Elo ratings');

        $this->info('Recalculating '.strtoupper($sport).' team metrics after stat refresh...');
        $this->callPipelineCommand($this->teamMetricCommands[$sport] ?? null, $season, strtoupper($sport).' team metrics');

        $this->callPipelineCommand($this->generatePredictionCommands[$sport] ?? null, $season, strtoupper($sport).' predictions');
    }

    private function callPipelineCommand(?string $command, int $season, string $label): void
    {
        if (! $command) {
            $this->warn("No command is configured for {$label}.");

            return;
        }

        $this->info("Running {$label}...");
        Artisan::call($command, [
            '--season' => $season,
        ]);
        $this->output->write(Artisan::output());
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
