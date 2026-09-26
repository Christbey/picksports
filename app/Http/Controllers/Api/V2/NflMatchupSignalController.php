<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\NFL\Game;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\NFL\Matchups\NflMatchupSignalCatalog;
use App\Services\NFL\Matchups\NflMatchupSignalService;
use App\Services\NFL\NflSituationalRecordService;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NflMatchupSignalController extends Controller
{
    public function __invoke(
        string $sport,
        string $game,
        Request $request,
        SportContextResolver $sports,
        SportGameQuery $games,
        NflMatchupSignalService $signals,
        NflSituationalRecordService $situations,
        SportsViewCache $cache,
    ): JsonResponse {
        abort_unless($sport === 'nfl', 404);
        $validated = $request->validate(['window' => ['sometimes', 'in:season_to_date,previous_season']]);
        $window = $validated['window'] ?? 'season_to_date';
        // Resolve through the existing access-controlled game query before cache lookup.
        $resolved = $games->find($sports->resolve($sport), $game, $request->user(), 'identity');
        abort_unless($resolved instanceof Game, 404);

        $key = $cache->contextHash([
            'contract' => NflMatchupSignalCatalog::VERSION,
            'window' => $window,
            'game_id' => $resolved->id,
            'updated_at' => $resolved->updated_at?->toIso8601String(),
        ]);
        $data = $cache->remember('nfl_matchup_signals', $key, 120, function () use ($resolved, $signals, $situations, $window): array {
            $matchup = $signals->build($resolved, $window);
            $resolved->loadMissing(['homeTeam', 'awayTeam']);
            $cutoff = $matchup['cutoff_at'] ? Carbon::parse($matchup['cutoff_at'])->utc() : null;
            // One narrow query for both teams, with no prediction/stat hydration.
            // No verified completion timestamp exists: same-day finals are excluded.
            $teamIds = [(int) $resolved->home_team_id, (int) $resolved->away_team_id];
            $history = $cutoff ? Game::query()
                ->whereBetween('season', [(int) $resolved->season - 3, (int) $resolved->season])
                ->where('season_type', '2')
                ->whereDate('game_date', '<', $cutoff->toDateString())
                ->where(fn ($query) => $query->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
                ->get(['id', 'home_team_id', 'away_team_id', 'season', 'season_type', 'week', 'status',
                    'game_date', 'game_time', 'home_score', 'away_score', 'neutral_site']) : collect();
            $records = [];
            foreach (['away' => $resolved->awayTeam, 'home' => $resolved->homeTeam] as $side => $team) {
                $records[$side] = [
                    'team_id' => $team?->id,
                    'label' => $team?->abbreviation ?? ucfirst($side),
                    'window' => 'Current and previous 3 regular seasons, before kickoff',
                    ...$situations->build($team ?? (object) ['id' => 0], $history),
                ];
            }

            // A separate record section is not a claim that every schedule/travel rule exists.
            $implemented = collect($records['home']['records'])->pluck('catalog_id')->filter()->all();
            foreach ($matchup['catalog'] as &$entry) {
                if ($entry['support'] === 'situational_records' && ! in_array($entry['id'], $implemented, true)) {
                    $entry['support'] = 'unavailable';
                    $entry['reason'] = $entry['id'] === 353
                        ? 'Incomplete source definition; home-rest threshold is unspecified.'
                        : 'Requires verified schedule, historical market, travel or contextual inputs and an implemented definition. Not inferred from other records.';
                }
            }
            unset($entry);
            $matchup['summary']['unsupported'] = collect($matchup['catalog'])->where('support', 'unavailable')->count();
            $matchup['summary']['situational_records'] = count($implemented);

            return [
                'role' => 'independent_matchup_analysis',
                'affects_prediction' => false,
                'matchup' => $matchup,
                'situational' => $records,
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => ['version' => 'v2', 'sport' => 'nfl', 'contract' => 'sports.games.matchup-signals.show',
                'game_id' => $resolved->id, 'cache_ttl_seconds' => 120],
        ]);
    }
}
