<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\CFB\Live\LiveMarketComparison;
use App\Support\PredictionFieldAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CfbLiveBettingController extends Controller
{
    public function __invoke(string $sport, string $game, Request $request, PredictionFieldAccess $access): JsonResponse
    {
        abort_unless($sport === 'cfb' && ctype_digit($game), 404);
        $model = Game::findOrFail((int) $game);
        $canSpread = $access->canViewField($request->user(), 'spread');
        $canProbability = $access->canViewField($request->user(), 'win_probability');
        $history = LivePredictionSnapshot::where('game_id', $model->id)->latest('id')->limit(25)->get();
        $latest = LivePredictionSnapshot::where('game_id', $model->id)->where('source', 'live_feed')->latest('id')->first()
            ?? $history->first();
        $final = LivePredictionSnapshot::where('game_id', $model->id)->where('source', 'live_feed')->where('status', 'final')->latest('id')->first();
        $stats = collect($final?->props ?? [])->filter(fn ($p) => $p['status'] === 'final')->keyBy(fn ($p) => $p['player_id'].':'.$p['market']);
        $commenceTime = CfbPropEligibility::kickoff($model)->toIso8601String();
        $serialize = function (LivePredictionSnapshot $snapshot) use ($canSpread, $canProbability, $stats, $model, $commenceTime) {
            $baseline = $snapshot->pregame;
            $projection = $snapshot->projection;
            foreach (['spread', 'total'] as $key) {
                if (! $canSpread) {
                    $baseline[$key] = null;
                    if ($projection) {
                        $projection[$key] = null;
                    }
                }
            }
            if (! $canProbability) {
                $baseline['home_win_probability'] = null;
                if ($projection) {
                    $projection['home_win_probability'] = null;
                }
            }
            $stale = $snapshot->status !== 'final' && ($snapshot->observed_at->lt(now()->subMinutes(3)) || ! in_array($model->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true));
            $markets = collect($snapshot->markets)->map(function ($market) use ($canSpread, $canProbability, $stale, $commenceTime, $model) {
                $allowed = $market['market'] === 'h2h' ? $canProbability : $canSpread;
                $market['lean_at_capture'] = $allowed ? $market['lean'] : null;
                $market['difference_at_capture'] = $allowed ? $market['difference'] : null;
                $market['fresh'] = ! $stale && $market['fresh'] && app(LiveMarketComparison::class)->fresh($market['updated_at'], $commenceTime);
                $market['result'] = null;
                if ($allowed && $model->status === 'STATUS_FINAL' && $model->home_score !== null && $model->away_score !== null && $market['lean']) {
                    $outcomes = collect($market['outcomes']);
                    $margin = $model->home_score - $model->away_score;
                    if ($market['market'] === 'totals') {
                        $line = $outcomes->firstWhere('name', 'Over')['point'] ?? null;
                        if (is_numeric($line)) {
                            $market['result'] = $model->home_score + $model->away_score == $line ? 'push'
                                : (($model->home_score + $model->away_score > $line) === ($market['lean'] === 'Over') ? 'win' : 'loss');
                        }
                    } elseif ($market['market'] === 'h2h') {
                        $market['result'] = $margin === 0 ? 'push' : (($margin > 0) === ($market['lean'] === 'Home') ? 'win' : 'loss');
                    } elseif ($market['market'] === 'spreads' && isset($market['home_line'])) {
                        $cover = $margin + $market['home_line'];
                        $market['result'] = $cover == 0 ? 'push' : (($cover > 0) === ($market['lean'] === 'Home') ? 'win' : 'loss');
                    }
                }
                if (! $market['fresh'] || ! $allowed) {
                    $market['difference'] = null;
                    $market['lean'] = null;
                }
                if ($stale) {
                    $market['fresh'] = false;
                }

                return $market;
            })->all();
            $props = collect($snapshot->props)->map(function ($prop) use ($stats, $stale, $commenceTime) {
                $actual = $stats->get($prop['player_id'].':'.$prop['market'])['actual'] ?? null;
                $prop['settled_actual'] = is_numeric($actual) ? (float) $actual : null;
                $prop['quotes'] = collect($prop['quotes'])->map(function ($quote) use ($actual, $stale, $commenceTime) {
                    $quote['lean_at_capture'] = $quote['lean'];
                    $quote['difference_at_capture'] = $quote['difference'];
                    $quote['result'] = null;
                    if (is_numeric($actual) && in_array($quote['lean'], ['Over', 'Under'], true)) {
                        $quote['result'] = (float) $actual === (float) $quote['line'] ? 'push'
                            : (((float) $actual > (float) $quote['line']) === ($quote['lean'] === 'Over') ? 'win' : 'loss');
                    }
                    $quote['fresh'] = ! $stale && $quote['fresh'] && app(LiveMarketComparison::class)->fresh($quote['updated_at'], $commenceTime);
                    if (! $quote['fresh']) {
                        $quote['fresh'] = false;
                        $quote['lean'] = null;
                        $quote['difference'] = null;
                    }

                    return $quote;
                })->all();

                return $prop;
            })->all();

            return ['id' => $snapshot->id, 'source' => $snapshot->source, 'observed_at' => $snapshot->observed_at->toIso8601String(),
                'status' => $snapshot->status, 'stale' => $stale, 'pregame' => $baseline, 'state' => $snapshot->state,
                'projection' => $projection, 'markets' => $markets, 'props' => $props];
        };

        return response()->json(['data' => $latest ? $serialize($latest) : null, 'history' => $history->map($serialize)->all(),
            'meta' => ['game_id' => $model->id, 'game_status' => $model->status, 'snapshot_count' => $model->liveSnapshots()->count(),
                'model' => 'cfb-live-score-clock-v1', 'player_prop_model' => 'cfb-live-production-rate-v1',
                'experimental' => true, 'refresh_seconds' => 30,
                'limitations' => ['Live comparisons are model differences, not calibrated betting edges.', 'Player estimates do not model depth-chart changes or teammate injury redistribution.', 'Overtime projections are withheld.'],
                'withheld_fields' => array_values(array_filter([! $canSpread ? 'spread_total' : null, ! $canProbability ? 'win_probability' : null]))]]);
    }
}
