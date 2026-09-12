<?php

namespace App\Services\CFB\Live;

use App\Actions\CFB\UpdateLivePrediction;
use App\Actions\ESPN\CFB\SyncPlayerStats;
use App\Actions\ESPN\Concerns\UpdatesGameFromSummary;
use App\Actions\GradePlayerProps;
use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\ESPN\CFB\EspnService;
use App\Services\OddsApi\OddsApiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LiveBettingSync
{
    use UpdatesGameFromSummary;

    public function __construct(protected EspnService $espn, protected OddsApiService $odds) {}

    public function execute(Game $game): array
    {
        $payload = $this->espn->getLiveGame((string) $game->espn_event_id);
        if (! is_array($payload) || (string) data_get($payload, 'header.id') !== (string) $game->espn_event_id
            || ! is_array(data_get($payload, 'header.competitions.0.status.type'))) {
            return ['status' => 'scoreboard_unavailable'];
        }
        $statsObserved = is_array(data_get($payload, 'boxscore.players')) && count(data_get($payload, 'boxscore.players')) > 0;
        DB::transaction(function () use ($game, $payload, $statsObserved) {
            $locked = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->updateGameFromSummary($payload, $locked);
            if ($statsObserved) {
                (new SyncPlayerStats)->execute($payload, $locked);
            }
        });
        $game->refresh();
        app(UpdateLivePrediction::class)->execute($game);
        $snapshot = LivePredictionSnapshot::where('game_id', $game->id)->latest('id')->first();
        if (! $snapshot || ! in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_FINAL'], true)) {
            return ['status' => 'no_baseline_or_not_live'];
        }
        $response = null;
        $marketStatus = 'not_requested';
        if ($game->status !== 'STATUS_FINAL' && $game->odds_api_event_id) {
            try {
                $response = $this->odds->getEventOdds('americanfootball_ncaaf', $game->odds_api_event_id,
                    ['h2h', 'spreads', 'totals', ...CfbPropEligibility::MARKETS], 'fanduel,draftkings', false);
            } catch (\Throwable) {
                // Do not expose API exception URLs, which can contain credentials.
                $response = null;
            }
            $marketStatus = 'unavailable';
            if (is_array($response) && ($response['id'] ?? '') === $game->odds_api_event_id && isset($response['commence_time'], $response['home_team'], $response['away_team'])
                && is_array($response['bookmakers'] ?? null) && CarbonImmutable::parse($response['commence_time'])->isPast()) {
                $marketStatus = $response['bookmakers'] === [] ? 'no_markets' : 'received';
            } else {
                $response = null;
            }
        }
        // Keep the quote and box-score timestamps together; no live prices touch game odds_data or pregame props.
        $projection = $snapshot->status === 'live' ? $snapshot->projection : null;
        $markets = $response ? app(LiveMarketComparison::class)->games($response, $projection) : [];
        $props = app(LivePropProjector::class)->project($game, $projection, $response, $statsObserved);
        $recorded = app(LiveSnapshotRecorder::class)->record($game, $snapshot->pregame, $snapshot->projection,
            $game->status === 'STATUS_FINAL' && ! $statsObserved ? 'final_pending_stats' : $snapshot->status, 'live_feed', $markets, $props);
        if ($game->status === 'STATUS_FINAL' && $statsObserved) {
            app(GradePlayerProps::class)->executeForGame('americanfootball_ncaaf', $game->id);
        }

        return ['status' => 'captured', 'snapshot_id' => $recorded->id, 'market_status' => $marketStatus,
            'markets' => count($markets), 'props' => count($props), 'props_with_live_quotes' => collect($props)->filter(fn ($p) => count($p['quotes']) > 0)->count()];
    }
}
