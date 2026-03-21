<?php

namespace App\Console\Commands\CBB;

use App\Actions\OddsApi\CBB\SyncOddsForGames;
use Illuminate\Console\Command;

class ReportOddsSyncQualityCommand extends Command
{
    protected $signature = 'cbb:report-odds-sync-quality
        {--days=7 : Number of upcoming days to inspect}
        {--show-unmatched : Show unmatched Odds API events}';

    protected $description = 'Report CBB odds sync coverage and unmatched Odds API events';

    public function handle(SyncOddsForGames $syncOddsForGames): int
    {
        $days = max(1, (int) $this->option('days'));
        $showUnmatched = (bool) $this->option('show-unmatched');
        $diagnostics = $syncOddsForGames->diagnostics($days);

        $this->info('CBB odds sync quality');
        $this->newLine();

        $this->table(
            ['Check', 'Value'],
            [
                ['Sport key', $diagnostics['sport_key']],
                ['Days ahead', $diagnostics['days_ahead']],
                ['Local actionable games', $diagnostics['local_games']],
                ['Local games with odds', $diagnostics['local_games_with_odds']],
                ['Odds API events returned', $diagnostics['api_events']],
                ['Odds API events in window', $diagnostics['in_window_events']],
                ['Matched API events', $diagnostics['matched_events']],
            ]
        );

        $unmatchedEvents = $diagnostics['unmatched_events'];

        if ($showUnmatched && $unmatchedEvents !== []) {
            $this->newLine();
            $this->warn('Sample unmatched events');
            $this->table(
                ['Event ID', 'Commence', 'Away', 'Home'],
                array_map(
                    fn (array $event) => [
                        $event['event_id'],
                        $event['commence_date'],
                        $event['away_team'],
                        $event['home_team'],
                    ],
                    $unmatchedEvents
                )
            );
        }

        return self::SUCCESS;
    }
}
