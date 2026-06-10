<?php

namespace App\Console\Commands\Sports;

use App\Actions\OddsApi\SyncFuturesOdds;
use Illuminate\Console\Command;

class SyncFuturesOddsCommand extends Command
{
    protected $signature = 'sports:sync-futures-odds
        {--sport=* : Sport slugs to sync (nba, mlb, nfl, cbb)}
        {--season= : Season to tag on stored futures rows}
        {--odds-sport=* : Override sport key mapping in format sport:odds_api_key}';

    protected $description = 'Sync futures/outrights odds from The Odds API';

    public function handle(SyncFuturesOdds $syncFuturesOdds): int
    {
        $sports = $this->resolvedSports();
        $season = $this->resolvedSeason();
        $overrides = $this->resolvedOddsSportOverrides();

        if ($sports === []) {
            $this->warn('No supported futures sports requested. Supported sports: nba, mlb, nfl, cbb.');

            return self::SUCCESS;
        }

        $this->info('Syncing futures odds for ['.implode(', ', $sports).']'.($season ? " (season {$season})" : '').'...');

        $results = $syncFuturesOdds->execute($sports, $season, $overrides);
        $total = array_sum($results);

        foreach ($results as $sport => $count) {
            $this->line(strtoupper($sport).": {$count} rows upserted");
        }

        if ($total === 0) {
            $this->warn('No futures odds rows were stored.');
        } else {
            $this->info("Stored/updated {$total} futures odds rows.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolvedSports(): array
    {
        $sports = $this->option('sport');
        if (! is_array($sports) || $sports === []) {
            return ['nba', 'mlb', 'nfl', 'cbb'];
        }

        $allowed = ['nba', 'mlb', 'nfl', 'cbb'];
        $normalized = array_values(array_unique(array_map(static fn ($sport) => strtolower((string) $sport), $sports)));
        $filtered = array_values(array_filter($normalized, static fn ($sport) => in_array($sport, $allowed, true)));

        return $filtered === [] ? [] : $filtered;
    }

    protected function resolvedSeason(): ?int
    {
        $season = $this->option('season');
        if ($season === null || $season === '') {
            return null;
        }

        return (int) $season;
    }

    /**
     * @return array<string, string>
     */
    protected function resolvedOddsSportOverrides(): array
    {
        $pairs = $this->option('odds-sport');
        if (! is_array($pairs) || $pairs === []) {
            return [];
        }

        $overrides = [];

        foreach ($pairs as $pair) {
            $pairString = trim((string) $pair);
            if ($pairString === '' || ! str_contains($pairString, ':')) {
                continue;
            }

            [$sport, $sportKey] = array_map('trim', explode(':', $pairString, 2));
            $sport = strtolower($sport);

            if ($sport === '' || $sportKey === '') {
                continue;
            }

            $overrides[$sport] = $sportKey;
        }

        return $overrides;
    }
}
