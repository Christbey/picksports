<?php

namespace App\Console\Commands\ESPN;

use App\Services\ESPN\HistoricalBackfillService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BackfillHistoricalDataCommand extends Command
{
    protected $signature = 'espn:backfill-historical
        {sport : Sport key (nba, wnba, cbb, wcbb, nfl, cfb)}
        {--season= : Season year using sport-specific season boundaries}
        {--from-date= : Start date in Y-m-d format}
        {--to-date= : End date in Y-m-d format}
        {--stage=full : full, scoreboard, or details}
        {--limit=0 : Limit number of detail games processed (0 = all)}
        {--sync : Run synchronously instead of dispatching jobs}
        {--force-details : Re-fetch all final-game details in range, not only missing ones}';

    protected $description = 'Backfill historical ESPN data for a sport using a shared, sport-agnostic workflow';

    public function handle(HistoricalBackfillService $service): int
    {
        $sport = strtolower((string) $this->argument('sport'));
        $stage = strtolower(trim((string) $this->option('stage')));
        $sync = (bool) $this->option('sync');
        $limit = max(0, (int) $this->option('limit'));
        $missingOnly = ! (bool) $this->option('force-details');

        if (! in_array($stage, ['full', 'scoreboard', 'details'], true)) {
            $this->error('The --stage option must be one of: full, scoreboard, details.');

            return self::FAILURE;
        }

        if ($stage === 'full' && ! $sync) {
            $this->error('Full historical backfill currently requires --sync so scoreboard shells exist before detail sync begins.');
            $this->line('Use `--stage=scoreboard` first, then run `--stage=details`, or rerun with `--sync`.');

            return self::FAILURE;
        }

        try {
            $definition = $service->definition($sport);
            $range = $service->resolveDateRange(
                $sport,
                $this->option('season') !== null ? (int) $this->option('season') : null,
                $this->option('from-date') !== null ? (string) $this->option('from-date') : null,
                $this->option('to-date') !== null ? (string) $this->option('to-date') : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $label = $definition['label'];
        $start = $range['start'];
        $end = $range['end'];

        if (in_array($stage, ['full', 'details'], true) && $definition['detail_job'] === null) {
            $this->error(sprintf(
                '%s does not have a shared ESPN detail-backfill job configured yet. Use `--stage=scoreboard` for now.',
                $label
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s historical backfill: %s stage for %s to %s (%s).',
            $label,
            $stage,
            $start->toDateString(),
            $end->toDateString(),
            $sync ? 'sync' : 'queued'
        ));

        if (in_array($stage, ['full', 'scoreboard'], true)) {
            $totalDays = (int) round($start->diffInDays($end) + 1);
            $this->line("Scoreboard dates to process: {$totalDays}");
            $bar = $this->output->createProgressBar($totalDays);
            $bar->start();

            $processedDays = $service->runScoreboardSync(
                $sport,
                $start,
                $end,
                $sync,
                function () use ($bar): void {
                    $bar->advance();
                }
            );

            $bar->finish();
            $this->newLine(2);
            $this->info(sprintf(
                '%s %s %d scoreboard date(s).',
                $sync ? 'Processed' : 'Queued',
                strtolower($label),
                $processedDays
            ));
        }

        if (in_array($stage, ['full', 'details'], true)) {
            $games = $service->pendingDetailGames($sport, $start, $end, $missingOnly, $limit);

            if ($games->isEmpty()) {
                $this->info('No eligible final games found for detail backfill in that range.');

                return self::SUCCESS;
            }

            $this->line(sprintf(
                'Detail games to process: %d%s',
                $games->count(),
                $missingOnly ? ' (missing plays/stats only)' : ' (all final games in range)'
            ));

            $bar = $this->output->createProgressBar($games->count());
            $bar->start();

            $processedGames = $service->runDetailSyncForGames(
                $sport,
                $games,
                $sync,
                function () use ($bar): void {
                    $bar->advance();
                }
            );

            $bar->finish();
            $this->newLine(2);
            $this->info(sprintf(
                '%s detail sync for %d %s game(s).',
                $sync ? 'Processed' : 'Queued',
                $processedGames,
                strtolower($label)
            ));
        }

        return self::SUCCESS;
    }
}
