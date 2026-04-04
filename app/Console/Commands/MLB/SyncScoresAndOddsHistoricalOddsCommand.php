<?php

namespace App\Console\Commands\MLB;

use App\Actions\ScoresAndOdds\MLB\SyncHistoricalOddsForGames;
use App\Services\OddsApi\Exceptions\OddsApiException;
use Illuminate\Console\Command;

class SyncScoresAndOddsHistoricalOddsCommand extends Command
{
    protected $signature = 'mlb:sync-scoresandodds-historical-odds
        {--season= : Local season to backfill}
        {--from-date= : Start date in Y-m-d format}
        {--to-date= : End date in Y-m-d format}
        {--limit=0 : Limit number of games processed}
        {--hydrate-current-when-empty : Copy scraped odds onto games.odds_data when empty}';

    protected $description = 'Sync historical MLB odds from ScoresAndOdds closing lines';

    public function handle(SyncHistoricalOddsForGames $action): int
    {
        $season = $this->option('season');
        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        $limit = max(0, (int) ($this->option('limit') ?? 0));
        $hydrateCurrentWhenEmpty = (bool) $this->option('hydrate-current-when-empty');

        if ($season !== null && ($fromDate !== null || $toDate !== null)) {
            $this->error('Use either --season or --from-date/--to-date, not both.');

            return self::FAILURE;
        }

        if ($season === null && $fromDate === null && $toDate === null) {
            $this->error('Provide --season or --from-date/--to-date.');

            return self::FAILURE;
        }

        $this->info('Syncing historical MLB odds from ScoresAndOdds...');

        try {
            $result = $action->execute(
                season: $season !== null ? (int) $season : null,
                fromDate: is_string($fromDate) && $fromDate !== '' ? $fromDate : null,
                toDate: is_string($toDate) && $toDate !== '' ? $toDate : null,
                limit: $limit,
                hydrateCurrentWhenEmpty: $hydrateCurrentWhenEmpty,
            );
        } catch (OddsApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Processed %d games, matched %d, created %d snapshots, hydrated %d current game rows.',
            (int) $result['processed_games'],
            (int) $result['matched_games'],
            (int) $result['created_snapshots'],
            (int) $result['hydrated_current_games'],
        ));

        $unmatched = $result['unmatched_games'] ?? [];
        if ($unmatched !== []) {
            $this->warn('Some games could not be matched to a ScoresAndOdds event:');

            foreach ($unmatched as $row) {
                $this->line(sprintf(
                    '  #%d %s %s @ %s (%s)',
                    (int) $row['game_id'],
                    (string) $row['game_date'],
                    (string) ($row['away_team'] ?? 'Unknown'),
                    (string) ($row['home_team'] ?? 'Unknown'),
                    (string) ($row['game_time'] ?? 'unknown')
                ));
            }
        }

        return self::SUCCESS;
    }
}
