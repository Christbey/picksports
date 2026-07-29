<?php

namespace App\Console\Commands\Sports;

use App\Models\GameOddsSnapshot;
use App\Services\OddsApi\MarketQuoteRecorder;
use Illuminate\Console\Command;

class BackfillMarketQuotesCommand extends Command
{
    protected $signature = 'sports:backfill-market-quotes
        {--sport=* : Sport keys to backfill (defaults to all)}
        {--limit=0 : Maximum snapshots to process}';

    protected $description = 'Normalize archived game odds snapshots into immutable per-book market quotes';

    public function handle(MarketQuoteRecorder $recorder): int
    {
        $sports = collect((array) $this->option('sport'))
            ->map(fn (mixed $sport): string => strtolower(trim((string) $sport)))
            ->filter()
            ->unique()
            ->values();
        $limit = max(0, (int) $this->option('limit'));
        $processed = 0;
        $quotes = 0;

        $query = GameOddsSnapshot::query()->orderBy('id');
        if ($sports->isNotEmpty()) {
            $query->whereIn('sport', $sports->all());
        }

        foreach ($query->lazyById(250) as $snapshot) {
            $quotes += $recorder->record($snapshot, (array) $snapshot->odds_data)->count();
            $processed++;

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $this->info("Processed {$processed} odds snapshots and normalized {$quotes} quote rows.");

        return self::SUCCESS;
    }
}
