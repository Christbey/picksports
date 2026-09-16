<?php

namespace App\Services\NFL;

use App\Models\BetDecision;
use App\Models\NFL\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\Predictions\NflPregameHorizon;
use App\Services\NFL\Predictions\NflPregameMarketSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NflPredictionDispositionRecorder
{
    public function __construct(private readonly NflPregameMarketSnapshot $markets) {}

    /**
     * Record a forecast disposition even when the betting gates decline it.
     * These immutable observations are never represented as executed bets.
     *
     * @param  list<string>  $candidateMarketKeys
     * @return Collection<int, BetDecision>
     */
    public function record(Prediction $prediction, array $candidateMarketKeys = []): Collection
    {
        $prediction->loadMissing('game.sportEvent');
        $game = $prediction->game;
        $start = $game?->sportEvent?->starts_at?->toImmutable();
        $now = now()->toImmutable();
        if ($game === null || $start === null || ! $now->lt($start)
            || ! in_array((string) $game->season_type, NflPregameHorizon::seasonTypes(), true)
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            return collect();
        }

        $snapshot = PredictionFeatureSnapshot::query()
            ->where('sport', 'nfl')->where('prediction_table', $prediction->getTable())
            ->where('prediction_id', $prediction->id)->where('pregame_safe', true)
            ->where('generated_at', '<=', $now)->where('generated_at', '<', $start)
            ->latest('generated_at')->latest('id')->first();
        $market = $this->markets->capture($game, $now, $start);
        $analysis = (array) data_get($prediction->model_metadata, 'analysis_layer', []);
        $decisions = collect();
        foreach (['moneyline' => 'h2h', 'spread' => 'spreads', 'total' => 'totals'] as $type => $key) {
            if (in_array($key, $candidateMarketKeys, true)) {
                continue;
            }

            $reference = (array) data_get($market, 'consensus.'.$type, []);
            $forecast = $type === 'moneyline' ? $prediction->win_probability
                : ($type === 'spread' ? $prediction->predicted_spread : $prediction->predicted_total);
            $line = is_numeric($reference['line'] ?? null) ? (float) $reference['line'] : null;
            $side = 'unknown';
            if (is_numeric($forecast)) {
                $side = match ($type) {
                    'moneyline' => (float) $forecast >= 0.5 ? 'home' : 'away',
                    'spread' => $line === null ? 'unknown' : ((float) $forecast + $line >= 0 ? 'home' : 'away'),
                    'total' => $line === null ? 'unknown' : ((float) $forecast >= $line ? 'over' : 'under'),
                };
            }
            $quote = in_array($side, ['away', 'under'], true)
                ? (array) ($reference['opposite_quote'] ?? []) : $reference;
            $holds = array_values((array) data_get($analysis, 'eligibility.data_reasons', []));
            if ($snapshot === null) {
                $holds[] = 'pregame_feature_snapshot_missing';
            }
            if (! is_numeric($quote['price'] ?? null)
                || ! is_numeric(data_get($reference, 'opposite_quote.price'))
                || $side === 'unknown') {
                $holds[] = 'fresh_paired_market_or_forecast_missing';
            }
            if (($analysis['applied'] ?? false) !== true) {
                $holds[] = 'analysis_not_applied';
            }
            $holds = array_values(array_unique($holds));
            $status = $holds === [] ? 'model_no_bet' : 'model_hold';
            $hash = hash('sha256', implode('|', [
                'nfl-forecast-disposition', $prediction->id, $snapshot?->id ?? 'missing', $key,
                $status, $quote['market_quote_id'] ?? 'missing',
            ]));
            $decisions->push(BetDecision::query()->firstOrCreate(['decision_hash' => $hash], [
                'decision_run_id' => (string) Str::uuid(),
                'model_run_id' => $snapshot?->model_run_id,
                'prediction_feature_snapshot_id' => $snapshot?->id,
                'game_odds_snapshot_id' => $market['game_odds_snapshot_id'] ?? null,
                'source_table' => $prediction->getTable(), 'source_id' => $prediction->id,
                'sport' => 'nfl', 'game_table' => $game->getTable(), 'game_id' => $game->id,
                'prediction_table' => $prediction->getTable(), 'prediction_id' => $prediction->id,
                'market_type' => $type, 'market_key' => $key, 'side' => $side,
                'line' => $type === 'moneyline' ? null : ($quote['line'] ?? null),
                'price' => $quote['price'] ?? null,
                'bookmaker' => $quote['bookmaker_key'] ?? null,
                'model_probability' => $type === 'moneyline' && is_numeric($forecast)
                    ? ($side === 'home' ? (float) $forecast : 1 - (float) $forecast) : null,
                'status' => $status, 'recommendation_label' => 'no_bet',
                'is_public' => false, 'is_tracking_only' => true, 'is_bet' => false,
                'pregame_safe' => $snapshot !== null && $side !== 'unknown' && is_numeric($quote['price'] ?? null),
                'eligibility_reasons' => array_values(array_unique([
                    ...$holds, ...(array) data_get($analysis, 'eligibility.model_reasons', []),
                    'official_candidate_not_released',
                ])),
                'risk_flags' => (array) ($analysis['risk_flags'] ?? []),
                'reason_codes' => (array) ($analysis['reason_codes'] ?? []),
                'explanation' => [
                    'authority' => 'nfl_legacy_forecast_observation',
                    'decision' => $status === 'model_hold' ? 'hold' : 'pass',
                    'execution_status' => 'not_recorded',
                    'hold_reasons' => $holds,
                    'forecast_value' => $forecast,
                    'analysis_layer' => $analysis,
                ],
                'feature_snapshot' => [
                    'prediction_feature_snapshot_id' => $snapshot?->id,
                    'feature_hash' => $snapshot?->feature_hash,
                    'outputs' => $snapshot?->outputs,
                    'model_version' => $prediction->model_version,
                ],
                'market_snapshot' => ['immutable_entry_quote' => true, ...$quote],
                'decided_at' => $now, 'locked_at' => $now, 'game_start_at' => $start,
            ]));
        }

        return $decisions;
    }
}
