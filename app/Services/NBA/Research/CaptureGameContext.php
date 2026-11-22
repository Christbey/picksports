<?php

namespace App\Services\NBA\Research;

use App\Models\NBA\Game;
use App\Models\NBA\ResearchContextSnapshot;
use App\Models\NBA\Team;
use App\Models\ResearchSource;
use App\Services\NBA\Injuries\InjuryCanaryScope;
use App\Services\NBA\Injuries\InjuryFeedClient;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Research\ResearchDocumentStore;
use Illuminate\Support\Facades\Cache;

class CaptureGameContext
{
    public static function sourceKey(Game $game): string
    {
        return 'espn-nba-context:'.$game->espn_event_id;
    }

    public static function summaryUrl(Game $game): string
    {
        return 'https://site.api.espn.com/apis/site/v2/sports/basketball/nba/summary?event='.$game->espn_event_id;
    }

    public static function identity(Game $game): string
    {
        return app(CanonicalPayloadHasher::class)->hash([$game->sport_event_id, $game->season, (string) $game->season_type,
            $game->home_team_id, $game->away_team_id, $game->sportEvent?->starts_at]);
    }

    public function capture(Game $game): ResearchContextSnapshot
    {
        if (! config('nba.injuries.observations_enabled') || ! config('nba.injuries.research_adapter_enabled') || ! config('research.enabled') || ! config('research.sports.nba.enabled')
            || ! app(InjuryCanaryScope::class)->allowsGame($game) || ! $game->sportEvent?->starts_at?->isFuture()
            || $game->sportEvent->sport !== 'nba' || (int) $game->season !== (int) $game->sportEvent->season
            || (string) $game->season_type !== (string) $game->sportEvent->season_type || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            || ! ctype_digit((string) $game->espn_event_id)) {
            throw new \RuntimeException('NBA context capture is outside the admitted pregame scope.');
        }
        $source = ResearchSource::query()->where('sport', 'nba')->where('source_key', self::sourceKey($game))->firstOrFail();
        if ($source->kind !== 'structured_espn' || $source->team_id !== null || $source->url !== self::summaryUrl($game)) {
            throw new \RuntimeException('Register an event context source explicitly.');
        }

        return Cache::lock('nba-context-capture:'.$game->id, 90)->block(2, function () use ($game, $source): ResearchContextSnapshot {
            $urls = ['summary' => self::summaryUrl($game)];
            foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $side => $id) {
                $team = Team::query()->findOrFail($id);
                if (! ctype_digit((string) $team->espn_id)) {
                    throw new \RuntimeException('Invalid provider team identity.');
                }
                $urls[$side.'_depth'] = 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/'.$game->season.'/teams/'.$team->espn_id.'/depthcharts';
            }
            $responses = [];
            foreach ($urls as $key => $url) {
                if (! app(ResearchDocumentStore::class)->allowed($source, $url)) {
                    throw new \RuntimeException('NBA context source is not approved.');
                }
                // One response per source; no reference expansion or hidden retries.
                $responses[$key] = app(InjuryFeedClient::class)->fetch($url, false);
            }
            $hash = app(CanonicalPayloadHasher::class)->hash($responses);

            return ResearchContextSnapshot::query()->firstOrCreate(['game_id' => $game->id, 'capture_hash' => $hash], [
                'source_id' => $source->id, 'identity_hash' => self::identity($game), 'responses' => $responses, 'captured_at' => now(),
            ]);
        });
    }
}
