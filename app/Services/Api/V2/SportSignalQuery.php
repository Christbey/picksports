<?php

namespace App\Services\Api\V2;

use App\Services\MLB\MlbBettingSignalService;
use App\Services\NBA\NbaBettingSignalService;
use App\Services\NFL\NflBettingSignalService;
use App\Support\SportsViewCache;
use Illuminate\Support\Carbon;

class SportSignalQuery
{
    public function __construct(
        private readonly NbaBettingSignalService $nbaSignals,
        private readonly MlbBettingSignalService $mlbSignals,
        private readonly NflBettingSignalService $nflSignals,
        private readonly SportsViewCache $sportsViewCache,
    ) {}

    /**
     * @param  array{season?: int, as_of_date?: Carbon}  $filters
     * @return array<string, mixed>
     */
    public function get(SportContext $context, array $filters = []): array
    {
        $service = $this->serviceFor($context);
        $season = (int) ($filters['season'] ?? config("{$context->slug}.season.default"));
        $asOfDate = $filters['as_of_date'] ?? now();
        $asOfDate = $asOfDate instanceof Carbon ? $asOfDate : Carbon::parse((string) $asOfDate);

        $cacheKey = $this->sportsViewCache->contextHash([
            'contract' => 'sports.signals.index',
            'sport' => $context->slug,
            'season' => $season,
            'as_of_date' => $asOfDate->toDateString(),
            'cache_minute' => $context->slug === 'mlb' ? $asOfDate->format('Y-m-d H:i') : null,
        ]);

        return $this->sportsViewCache->remember(
            segment: SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
            key: $cacheKey,
            ttlSeconds: $context->slug === 'mlb' ? 30 : 120,
            resolver: fn (): array => $service->signals($season, $asOfDate),
        );
    }

    private function serviceFor(SportContext $context): NbaBettingSignalService|MlbBettingSignalService|NflBettingSignalService
    {
        return match ($context->slug) {
            'nba' => $this->nbaSignals,
            'mlb' => $this->mlbSignals,
            'nfl' => $this->nflSignals,
            default => abort(404, "Betting signals are not available for {$context->slug}."),
        };
    }
}
