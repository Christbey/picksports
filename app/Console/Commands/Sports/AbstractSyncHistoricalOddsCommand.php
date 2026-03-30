<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use Illuminate\Console\Command;

abstract class AbstractSyncHistoricalOddsCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const SYNC_ACTION_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $season = $this->option('season');
        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        $hoursBefore = max(0, (int) ($this->option('hours-before') ?? 24));
        $limit = max(0, (int) ($this->option('limit') ?? 0));
        $oddsSport = $this->option('odds-sport');
        $oddsSport = is_string($oddsSport) && $oddsSport !== '' ? $oddsSport : null;
        $oddsSport ??= $this->defaultOddsSportKey();
        $hydrateCurrentWhenEmpty = (bool) $this->option('hydrate-current-when-empty');

        if ($season !== null && ($fromDate !== null || $toDate !== null)) {
            $this->error('Use either --season or --from-date/--to-date, not both.');

            return self::FAILURE;
        }

        if ($season === null && $fromDate === null && $toDate === null) {
            $this->error('Provide --season or --from-date/--to-date.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Syncing historical odds snapshots %d hour(s) before tip%s...',
            $hoursBefore,
            $oddsSport !== null ? " using sport key [{$oddsSport}]" : ''
        ));

        $result = app($this->syncActionClass())->executeHistorical(
            hoursBefore: $hoursBefore,
            season: $season !== null ? (int) $season : null,
            fromDate: is_string($fromDate) && $fromDate !== '' ? $fromDate : null,
            toDate: is_string($toDate) && $toDate !== '' ? $toDate : null,
            limit: $limit,
            oddsSportKey: $oddsSport,
            hydrateCurrentWhenEmpty: $hydrateCurrentWhenEmpty,
        );

        $this->info(sprintf(
            'Processed %d games, matched %d, created %d snapshots, hydrated %d current game rows.',
            (int) $result['processed_games'],
            (int) $result['matched_games'],
            (int) $result['created_snapshots'],
            (int) $result['hydrated_current_games'],
        ));

        $unmatched = $result['unmatched_games'] ?? [];
        if ($unmatched !== []) {
            $this->warn('Some games could not be matched to a historical odds event:');
            foreach ($unmatched as $row) {
                $this->line(sprintf(
                    '  #%d %s %s %s at %s (%s)',
                    (int) $row['game_id'],
                    (string) $row['game_date'],
                    (string) ($row['away_team'] ?? 'Unknown'),
                    (string) ($row['home_team'] ?? 'Unknown'),
                    (string) ($row['game_time'] ?? 'unknown'),
                    (string) $row['target_timestamp']
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return class-string
     */
    protected function syncActionClass(): string
    {
        return $this->requiredString(static::SYNC_ACTION_CLASS, 'SYNC_ACTION_CLASS must be defined on sync-historical-odds command.');
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season= : Local season to backfill}\n {--from-date= : Start date in Y-m-d format}\n {--to-date= : End date in Y-m-d format}\n {--hours-before=24 : Hours before scheduled tip to fetch the historical snapshot}\n {--limit=0 : Limit number of games processed}\n {--odds-sport= : Override Odds API sport key}\n {--hydrate-current-when-empty : Copy the historical snapshot onto games.odds_data when no current odds exist}",
            $this->commandName()
        );
    }

    protected function commandName(): string
    {
        return $this->requiredString(static::COMMAND_NAME, 'COMMAND_NAME must be defined.');
    }

    protected function commandDescription(): string
    {
        return $this->requiredString(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION must be defined.');
    }

    protected function defaultOddsSportKey(): ?string
    {
        return null;
    }
}
