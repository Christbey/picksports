<?php

namespace App\Actions\OddsApi\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\PlayerProp;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SyncPlayerPropsForGames
{
    public function __construct(protected OddsApiService $api) {}

    public function execute(?string $date = null, ?int $gameId = null, bool $analyze = true): array
    {
        $query = Game::where('status', 'STATUS_SCHEDULED')->whereNotNull('odds_api_event_id');
        CfbPropEligibility::onDate($query, $date ?? now('America/Chicago')->toDateString());
        $games = $query->when($gameId, fn ($q) => $q->whereKey($gameId))->get();
        $counts = ['games' => 0, 'stored' => 0, 'empty' => 0, 'failed' => 0, 'recommendations' => 0];
        foreach ($games as $game) {
            if (CfbPropEligibility::kickoff($game)->isPast()) {
                continue;
            }
            $counts['games']++;
            try {
                $response = $this->api->getPlayerProps($game->odds_api_event_id, 'americanfootball_ncaaf', CfbPropEligibility::MARKETS, 'fanduel,draftkings');
            } catch (\Throwable) {
                // API exception URLs may contain credentials. Report only a failure count.
                $counts['failed']++;

                continue;
            }
            if (! is_array($response) || ($response['id'] ?? null) !== $game->odds_api_event_id
                || ! isset($response['bookmakers'], $response['commence_time'])
                || ! is_array($response['bookmakers'])) {
                $counts['failed']++;

                continue;
            }
            if (CarbonImmutable::parse($response['commence_time'])->isPast() || $game->fresh()->status !== 'STATUS_SCHEDULED' || CfbPropEligibility::kickoff($game)->isPast()) {
                continue;
            }
            $stored = DB::transaction(function () use ($response, $game) {
                // Serialize concurrent refreshes, and retain old lines and their grades.
                Game::whereKey($game->id)->lockForUpdate()->first();
                PlayerProp::where('game_id', $game->id)->whereNull('graded_at')->update(['is_current' => false]);
                $stored = 0;
                foreach ($response['bookmakers'] as $book) {
                    if (! in_array($book['key'] ?? '', ['fanduel', 'draftkings'], true)) {
                        continue;
                    }
                    foreach ($book['markets'] ?? [] as $market) {
                        if (! in_array($market['key'] ?? '', CfbPropEligibility::MARKETS, true)) {
                            continue;
                        }
                        $lines = [];
                        foreach ($market['outcomes'] ?? [] as $outcome) {
                            if (! is_string($outcome['description'] ?? null) || ! is_numeric($outcome['point'] ?? null)
                                || ! is_numeric($outcome['price'] ?? null) || ! in_array($outcome['name'] ?? '', ['Over', 'Under'], true)) {
                                continue;
                            }
                            $key = hash('sha256', json_encode([$game->id, $book['key'], $market['key'], $outcome['description'], number_format((float) $outcome['point'], 2, '.', '')]));
                            $lines[$key]['player_name'] = $outcome['description'];
                            $lines[$key]['line'] = $outcome['point'];
                            $lines[$key][strtolower($outcome['name']).'_price'] = (int) $outcome['price'];
                        }
                        foreach ($lines as $key => $line) {
                            $prop = PlayerProp::where('quote_key', $key)->first() ?? new PlayerProp;
                            if ($prop->graded_at) {
                                continue;
                            }
                            $player = CfbPropEligibility::matchPlayer($game, $line['player_name']);
                            $prop->forceFill([
                                'quote_key' => $key, 'is_current' => true,
                                'game_id' => $game->id, 'player_id' => $player?->id,
                                'odds_api_event_id' => $game->odds_api_event_id,
                                'market' => $market['key'], 'bookmaker' => $book['key'],
                                'over_price' => null, 'under_price' => null,
                                ...$line,
                                'raw_data' => ['market' => $market, 'last_update' => $market['last_update'] ?? $book['last_update'] ?? null],
                                'fetched_at' => now(), 'recommended_side' => null, 'confidence_score' => null,
                                'predicted_over_probability' => null, 'market_over_probability' => null,
                                'edge_probability' => null, 'confidence_decomposition' => null,
                                'narrative_json' => null, 'narrative_input_hash' => null,
                            ])->save();
                            $stored++;
                        }
                    }
                }

                return $stored;
            });
            $counts['stored'] += $stored;
            $counts['empty'] += $stored === 0 ? 1 : 0;
            if ($analyze && $stored > 0) {
                $counts['recommendations'] += app(PlayerPropAnalyzer::class)->analyzeProps(sport: 'CFB', gameFilter: $game->id, attachNarratives: false)->count();
            }
        }
        app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);

        return $counts;
    }
}
