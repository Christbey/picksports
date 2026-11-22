<?php

namespace App\Services\NBA\Injuries;

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\ResearchSource;
use App\Services\NBA\Research\CaptureGameContext;
use App\Services\Research\ResearchDocumentStore;

/** Bridge-local cap, including cache reads; never changes normal ingest clients. */
class BoundedInjuryFeedClient extends InjuryFeedClient
{
    public int $used = 0;

    private ?int $sourceId = null;

    private ?int $gameId = null;

    public function authorizeWith(ResearchSource $source, ?Game $game = null): void
    {
        $this->sourceId = $source->id;
        $this->gameId = $game?->id;
    }

    public function __construct(private readonly int $maximum) {}

    public function fetch(string $url, bool $publishCache = true): array
    {
        if ($this->used >= $this->maximum) {
            return ['url' => $url, 'checked_at' => now()->toIso8601String(), 'fetched_at' => null,
                'cache_hit' => false, 'http_status' => null, 'error_code' => 'request_budget_exhausted', 'payload' => null];
        }
        $this->used++;
        $source = $this->sourceId ? ResearchSource::find($this->sourceId) : null;
        $game = $this->gameId ? Game::with('sportEvent')->find($this->gameId) : null;
        $team = $source?->team_id ? Team::find($source->team_id) : null;
        $approvedUrl = preg_replace('/^http:/i', 'https:', $url);
        $scope = app(InjuryCanaryScope::class);
        $hour = now('America/Chicago')->hour;
        if (! config('nba.source_bridge.enabled', false) || ($hour >= 8 && $hour < 23)
            || ! $source || ($source->team_id !== null ? (! $team || ! $scope->allowsTeam($source->team_id)
                || $source->source_key !== StructuredInjuryEvidence::sourceKey((string) $team->espn_id))
                : (! $game || ! $scope->allowsGame($game) || ! $game->sportEvent?->starts_at?->isFuture()
                    || ! $game->sportEvent->starts_at->lte(now()->addHours(8)) || $game->sportEvent->sport !== 'nba'
                    || (int) $game->sportEvent->season !== (int) $game->season
                    || (string) $game->sportEvent->season_type !== (string) $game->season_type
                    || $source->source_key !== CaptureGameContext::sourceKey($game) || $source->url !== CaptureGameContext::summaryUrl($game)
                    || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)))
            || $source->sport !== 'nba' || $source->kind !== 'structured_espn'
            || ! app(ResearchDocumentStore::class)->allowed($source, $approvedUrl)) {
            return ['url' => $url, 'checked_at' => now()->toIso8601String(), 'fetched_at' => null,
                'cache_hit' => false, 'http_status' => null, 'error_code' => 'source_not_approved', 'payload' => null];
        }

        return parent::fetch($url, $publishCache);
    }
}
