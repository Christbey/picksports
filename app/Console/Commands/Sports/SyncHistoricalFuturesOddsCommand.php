<?php

namespace App\Console\Commands\Sports;

use App\Actions\OddsApi\SyncHistoricalFuturesOdds;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncHistoricalFuturesOddsCommand extends Command
{
    protected $signature = 'sports:sync-historical-futures-odds
        {--sport=* : Sport slugs to sync (nba, mlb, nfl, cbb)}
        {--season= : Season to tag on stored futures rows}
        {--date=* : ISO-8601 timestamps to capture}
        {--from-date= : Start date in Y-m-d format}
        {--to-date= : End date in Y-m-d format}
        {--daily : Expand from/to into one snapshot per day}
        {--hour=12 : UTC hour to use for daily snapshots}
        {--market=* : Futures markets to capture (defaults to outrights)}
        {--bookmaker=draftkings : Bookmaker key to capture}
        {--odds-sport=* : Override sport key mapping in format sport:odds_api_key}';

    protected $description = 'Sync historical futures/outrights odds snapshots from The Odds API';

    public function handle(SyncHistoricalFuturesOdds $syncHistoricalFuturesOdds): int
    {
        $dates = $this->resolvedDates();
        if ($dates === []) {
            $this->error('Provide either --date timestamps or a --from-date/--to-date range with --daily.');

            return self::FAILURE;
        }

        $sports = $this->resolvedSports();
        $season = $this->resolvedSeason();
        $overrides = $this->resolvedOddsSportOverrides();
        $markets = $this->resolvedMarkets();
        $bookmaker = trim((string) $this->option('bookmaker'));

        if ($sports === []) {
            $this->warn('No supported historical futures sports requested. Supported sports: nba, mlb, nfl, cbb.');

            return self::SUCCESS;
        }

        $this->info(
            'Syncing historical futures snapshots for ['.implode(', ', $sports)
            .'] markets ['.implode(', ', $markets)
            .'] at ['.implode(', ', $dates).']...'
        );

        $results = $syncHistoricalFuturesOdds->executeHistorical(
            sports: $sports,
            dates: $dates,
            season: $season,
            oddsSportOverrides: $overrides,
            markets: $markets,
            bookmaker: $bookmaker !== '' ? $bookmaker : 'draftkings',
        );
        $total = array_sum($results);

        foreach ($results as $sport => $count) {
            $this->line(strtoupper($sport).": {$count} snapshot rows upserted");
        }

        $this->info("Stored/updated {$total} historical futures snapshot rows.");

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
     * @return array<int, string>
     */
    protected function resolvedDates(): array
    {
        $dates = $this->option('date');
        if (is_array($dates) && $dates !== []) {
            return array_values(array_unique(array_map(
                static fn ($date) => Carbon::parse((string) $date)->utc()->format('Y-m-d\TH:i:s\Z'),
                $dates
            )));
        }

        if (! $this->option('daily')) {
            return [];
        }

        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        if (! is_string($fromDate) || ! is_string($toDate) || trim($fromDate) === '' || trim($toDate) === '') {
            return [];
        }

        $hour = max(0, min(23, (int) $this->option('hour')));
        $cursor = Carbon::parse($fromDate, 'UTC')->startOfDay()->setHour($hour);
        $end = Carbon::parse($toDate, 'UTC')->startOfDay()->setHour($hour);
        $resolved = [];

        while ($cursor->lte($end)) {
            $resolved[] = $cursor->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            $cursor->addDay();
        }

        return $resolved;
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

    /**
     * @return array<int, string>
     */
    protected function resolvedMarkets(): array
    {
        $markets = $this->option('market');
        if (! is_array($markets) || $markets === []) {
            return ['outrights'];
        }

        $resolved = array_values(array_unique(array_filter(array_map(
            static fn ($market) => trim((string) $market),
            $markets
        ))));

        return $resolved === [] ? ['outrights'] : $resolved;
    }
}
