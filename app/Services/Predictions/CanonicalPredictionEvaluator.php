<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\PredictionEvaluation;
use App\Models\PredictionMarket;
use App\Models\SportEventResult;
use Illuminate\Support\Facades\DB;

class CanonicalPredictionEvaluator
{
    public const SCORING_VERSION = 'canonical-v1';

    public const NFL_MARKET_SCORING_VERSION = 'canonical-nfl-market-v2';

    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function evaluate(
        CanonicalPrediction $prediction,
        SportEventResult $result,
    ): PredictionEvaluation {
        if ($prediction->sport_event_id !== $result->sport_event_id) {
            throw new PredictionLifecycleException('Prediction and result must belong to the same canonical event.');
        }

        if (! in_array($prediction->publication_state, ['published', 'superseded'], true)
            || $prediction->published_at === null) {
            throw new PredictionLifecycleException('Only published canonical prediction revisions can be evaluated.');
        }

        $prediction->loadMissing('markets', 'calculationRun.inputSnapshot');
        $metrics = $this->score($prediction, $result);
        $scoringVersion = $this->scoringVersion($prediction);
        $evaluationHash = $this->hasher->hash([
            'prediction' => $prediction->public_id,
            'prediction_output' => $prediction->output_hash,
            'result' => $result->result_hash,
            'scoring_version' => $scoringVersion,
        ]);

        return DB::transaction(function () use ($prediction, $result, $metrics, $evaluationHash, $scoringVersion): PredictionEvaluation {
            CanonicalPrediction::query()->lockForUpdate()->findOrFail($prediction->getKey());

            $existing = PredictionEvaluation::query()
                ->where('evaluation_hash', $evaluationHash)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $latest = PredictionEvaluation::query()
                ->where('canonical_prediction_id', $prediction->getKey())
                ->orderByDesc('evaluation_revision')
                ->first();

            return PredictionEvaluation::query()->create([
                'canonical_prediction_id' => $prediction->getKey(),
                'sport_event_id' => $prediction->sport_event_id,
                'sport_event_result_id' => $result->getKey(),
                'evaluation_revision' => ($latest?->evaluation_revision ?? 0) + 1,
                'supersedes_prediction_evaluation_id' => $latest?->getKey(),
                'sport' => $prediction->sport,
                'prediction_phase' => $prediction->phase,
                'scoring_version' => $scoringVersion,
                'evaluation_hash' => $evaluationHash,
                'prediction_table' => null,
                'prediction_id' => null,
                'game_id' => null,
                'model_version' => $prediction->model_version,
                'feature_version' => $prediction->feature_version,
                'blend_version' => $prediction->blend_version,
                'actuals' => $metrics['actuals'],
                'errors' => $metrics['errors'],
                'market_comparison' => $metrics['market_comparison'],
                'evaluated_at' => now(),
            ]);
        });
    }

    /** @return array{actuals:array<string,mixed>,errors:array<string,mixed>,market_comparison:array<string,mixed>} */
    private function score(CanonicalPrediction $prediction, SportEventResult $result): array
    {
        $homeMoneyline = $this->market($prediction, 'moneyline', 'home');
        $homeSpread = $this->market($prediction, 'spread', 'home');
        $total = $this->market($prediction, 'total', 'combined');
        $spreadConvention = data_get($prediction->output_metadata, 'market_conventions.spread');

        if ($spreadConvention !== 'sportsbook_home_line') {
            throw new PredictionLifecycleException('Canonical evaluation requires the sportsbook home-line spread convention.');
        }

        $homeWinProbability = $this->requiredNumber($homeMoneyline->probability, 'home moneyline probability');
        $homeLine = $this->requiredNumber($homeSpread->projected_line, 'home spread line');
        $predictedTotal = $this->requiredNumber($total->projected_line, 'predicted total');

        if ($homeWinProbability <= 0 || $homeWinProbability >= 1) {
            throw new PredictionLifecycleException('Home moneyline probability must be strictly between zero and one.');
        }

        $actualHomeMargin = $result->home_score - $result->away_score;

        if ($actualHomeMargin === 0 && $prediction->sport !== 'nfl') {
            throw new PredictionLifecycleException('Canonical winner evaluation does not support tied final results.');
        }

        $actualTotal = $result->home_score + $result->away_score;
        $actualHomeWin = $actualHomeMargin === 0 ? null : ($actualHomeMargin > 0 ? 1.0 : 0.0);
        $predictedHomeMargin = -$homeLine;
        $spreadSignedError = $actualHomeMargin - $predictedHomeMargin;
        $totalSignedError = $actualTotal - $predictedTotal;

        $marketComparison = [
            'home_win_probability' => round($homeWinProbability, 6),
            'home_spread_line' => round($homeLine, 4),
            'predicted_total' => round($predictedTotal, 4),
            'spread_convention' => $spreadConvention,
        ];

        if ($prediction->sport === 'nfl') {
            $marketComparison['pregame_market'] = $this->nflPregameMarketComparison(
                $prediction,
                $actualHomeMargin,
                $actualTotal,
                $predictedHomeMargin,
                $predictedTotal,
            );
        }

        return [
            'actuals' => [
                'home_score' => $result->home_score,
                'away_score' => $result->away_score,
                'home_margin' => $actualHomeMargin,
                'total_points' => $actualTotal,
                'winner' => $actualHomeWin === null ? 'tie' : ($actualHomeWin === 1.0 ? 'home' : 'away'),
            ],
            'errors' => [
                'winner_correct' => $actualHomeWin === null ? null : ($homeWinProbability >= 0.5) === ($actualHomeWin === 1.0),
                'brier_score' => $actualHomeWin === null ? null : round(($homeWinProbability - $actualHomeWin) ** 2, 8),
                'log_loss' => $actualHomeWin === null ? null : round(-(($actualHomeWin * log($homeWinProbability))
                    + ((1 - $actualHomeWin) * log(1 - $homeWinProbability))), 8),
                'predicted_home_margin' => round($predictedHomeMargin, 4),
                'home_margin_signed_error' => round($spreadSignedError, 4),
                'spread_absolute_error' => round(abs($spreadSignedError), 4),
                'predicted_total' => round($predictedTotal, 4),
                'total_signed_error' => round($totalSignedError, 4),
                'total_absolute_error' => round(abs($totalSignedError), 4),
            ],
            'market_comparison' => $marketComparison,
        ];
    }

    private function scoringVersion(CanonicalPrediction $prediction): string
    {
        return $prediction->sport === 'nfl'
            && data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'pregame_market.available') === true
                ? self::NFL_MARKET_SCORING_VERSION
                : self::SCORING_VERSION;
    }

    /** @return array<string, mixed> */
    private function nflPregameMarketComparison(
        CanonicalPrediction $prediction,
        int $actualHomeMargin,
        int $actualTotal,
        float $predictedHomeMargin,
        float $predictedTotal,
    ): array {
        $snapshot = (array) data_get(
            $prediction->calculationRun?->inputSnapshot?->inputs,
            'pregame_market',
            [],
        );
        $spread = (array) data_get($snapshot, 'consensus.spread', []);
        $total = (array) data_get($snapshot, 'consensus.total', []);
        $marketHomeLine = $this->optionalNumber($spread['line'] ?? null);
        $marketAwayLine = $this->optionalNumber(data_get($spread, 'opposite_quote.line'));
        $marketTotal = $this->optionalNumber($total['line'] ?? null);
        $spreadEdge = $marketHomeLine === null
            ? null
            : $predictedHomeMargin - (-$marketHomeLine);
        $spreadSide = $spreadEdge === null || abs($spreadEdge) < 0.000001
            ? null
            : ($spreadEdge > 0 ? 'home' : 'away');
        $spreadSelectionLine = match ($spreadSide) {
            'home' => $marketHomeLine,
            'away' => $marketAwayLine ?? ($marketHomeLine === null ? null : -$marketHomeLine),
            default => null,
        };
        $spreadResultValue = match ($spreadSide) {
            'home' => $spreadSelectionLine === null ? null : $actualHomeMargin + $spreadSelectionLine,
            'away' => $spreadSelectionLine === null ? null : -$actualHomeMargin + $spreadSelectionLine,
            default => null,
        };
        $totalEdge = $marketTotal === null ? null : $predictedTotal - $marketTotal;
        $totalSide = $totalEdge === null || abs($totalEdge) < 0.000001
            ? null
            : ($totalEdge > 0 ? 'over' : 'under');
        $totalResultValue = match ($totalSide) {
            'over' => $marketTotal === null ? null : $actualTotal - $marketTotal,
            'under' => $marketTotal === null ? null : $marketTotal - $actualTotal,
            default => null,
        };

        return [
            'available' => ($snapshot['available'] ?? false) === true,
            'source' => $snapshot['source'] ?? null,
            'event_input_snapshot_id' => $prediction->calculationRun?->inputSnapshot?->public_id,
            'game_odds_snapshot_id' => $snapshot['game_odds_snapshot_id'] ?? null,
            'captured_at' => $snapshot['captured_at'] ?? null,
            'spread' => [
                'market_home_line' => $marketHomeLine,
                'model_home_margin' => round($predictedHomeMargin, 4),
                'edge_points' => $spreadEdge === null ? null : round($spreadEdge, 4),
                'selection' => $spreadSide,
                'selection_line' => $spreadSelectionLine,
                'result_value' => $spreadResultValue,
                'result' => $this->grade($spreadResultValue),
                'market_quote_id' => $spread['market_quote_id'] ?? null,
                'quote_hash' => $spread['quote_hash'] ?? null,
            ],
            'total' => [
                'market_line' => $marketTotal,
                'model_total' => round($predictedTotal, 4),
                'edge_points' => $totalEdge === null ? null : round($totalEdge, 4),
                'selection' => $totalSide,
                'result_value' => $totalResultValue,
                'result' => $this->grade($totalResultValue),
                'market_quote_id' => $total['market_quote_id'] ?? null,
                'quote_hash' => $total['quote_hash'] ?? null,
            ],
        ];
    }

    private function grade(?float $resultValue): ?string
    {
        if ($resultValue === null) {
            return null;
        }

        return match (true) {
            abs($resultValue) < 0.000001 => 'push',
            $resultValue > 0 => 'win',
            default => 'loss',
        };
    }

    private function optionalNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function market(CanonicalPrediction $prediction, string $type, string $selection): PredictionMarket
    {
        $markets = $prediction->markets
            ->where('market_type', $type)
            ->where('selection', $selection);

        if ($markets->count() !== 1) {
            throw new PredictionLifecycleException("Canonical evaluation requires exactly one {$type}:{$selection} market.");
        }

        return $markets->first();
    }

    private function requiredNumber(mixed $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw new PredictionLifecycleException("Canonical evaluation requires a numeric {$field}.");
        }

        return (float) $value;
    }
}
