<?php

namespace App\Services\CFB\Predictions;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\PredictionMarket;
use App\Services\CFB\CfbMarketMovementSignalService;
use App\Services\CFB\CfbSpreadMarketConfirmation;

class CfbCanonicalSpreadValueSignalService
{
    public const DECISION_POLICY_VERSION = 'cfb-spread-decisions-2';

    public function __construct(private readonly CfbMarketMovementSignalService $marketMovement) {}

    /** @return array<string, mixed>|null */
    public function forPrediction(CanonicalPrediction $prediction, Game $game): ?array
    {
        if (! (bool) config('cfb.predictions.spread_value.enabled', true)
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            return null;
        }

        $homeSpread = $prediction->markets->first(
            fn (PredictionMarket $market): bool => $market->market_type === 'spread'
                && $market->selection === 'home',
        );
        $modelHomeLine = $this->number($homeSpread?->projected_line);
        if ($modelHomeLine === null) {
            return null;
        }

        $modelHomeMargin = -$modelHomeLine;
        $market = $this->marketMovement->spreadContext($game, $modelHomeMargin);
        if ($market === null) {
            return null;
        }

        $marketHomeLine = $this->number($market['current_bookmaker_home_line'] ?? null);
        $marketHomeMargin = $this->number($market['current_home_margin'] ?? null);
        if ($marketHomeLine === null || $marketHomeMargin === null) {
            return null;
        }

        $signedHomeEdge = $modelHomeMargin - $marketHomeMargin;
        $side = $signedHomeEdge >= 0 ? 'home' : 'away';
        $confirmation = app(CfbSpreadMarketConfirmation::class)->assess($game, $side, $modelHomeMargin);
        $executable = $confirmation['best_quote'];
        if ($executable !== null) {
            $marketHomeLine = $side === 'home' ? $executable['line'] : -$executable['line'];
            $marketHomeMargin = -$marketHomeLine;
        }
        $edgePoints = round($side === 'home' ? $modelHomeMargin - $marketHomeMargin : $marketHomeMargin - $modelHomeMargin, 1);
        $probability = app(CfbSpreadCoverProbabilityService::class)->assess(
            $prediction, $side, $side === 'home' ? $marketHomeLine : -$marketHomeLine,
            $executable['price'] ?? null,
        );
        $minimumEdge = (float) config('cfb.predictions.spread_value.minimum_edge_points', 3.0);
        $keyEdge = (float) config('cfb.predictions.spread_value.key_edge_points', 7.0);
        $support = $this->statisticalSupport($prediction);
        $marketHealth = ['supported' => $confirmation['supported'],
            'observed_at' => $executable['provider_observed_at'] ?? null,
            'risk_flags' => $confirmation['risk_flags']];
        $probabilityFlags = $probability['risk_flags'];
        if (! $probability['positive_expected_value'] && $probability['status'] === 'calibrated') {
            $probabilityFlags[] = 'non_positive_calibrated_ev';
        }
        $assessment = app(CfbSpreadAssessment::class)->assess(
            (array) ($prediction->calculationRun?->inputSnapshot?->inputs ?? []),
            $modelHomeMargin, $marketHomeLine, [...$support['risk_flags'], ...$marketHealth['risk_flags'], ...$probabilityFlags,
                ...($prediction->calculationRun?->inputSnapshot?->pregame_safety_status !== 'verified' ? ['unverified_pregame_snapshot'] : []),
                ...(! $prediction->generated_at || $prediction->generated_at->lt(now()->subHours(6))
                    || $prediction->generated_at->gt(now()) ? ['stale_prediction'] : [])],
        );
        $assessment['cover_probability'] = $probability['cover_probability'];
        $assessment['probability_status'] = $probability['status'];
        if (! $support['model_inputs_qualified']
            && (bool) config('cfb.predictions.spread_value.suppress_unqualified_model_inputs', true)) {
            return [
                'decision_policy_version' => self::DECISION_POLICY_VERSION,
                'decision_status' => 'unavailable',
                'decision_summary' => 'No reliable spread selection: essential model inputs are missing.',
                'decision_notes' => $this->decisionNotes($assessment['risk_flags']),
                'has_playable_value' => false,
                'play_count' => 0,
                'spread_assessment' => $assessment,
                'market_confirmation' => $confirmation,
                'cover_probability_evidence' => $probability,
                'best' => null,
            ];
        }

        $isPlayable = $edgePoints >= $minimumEdge && $support['supported'] && $marketHealth['supported'] && $probability['positive_expected_value'] && $assessment['risk_flags'] === [];
        $isKeyEdge = $isPlayable && $edgePoints >= $keyEdge;
        $team = $side === 'home' ? $game->homeTeam : $game->awayTeam;
        $teamLabel = $team?->abbreviation ?: $team?->display_name ?: $team?->school ?: ucfirst($side);
        $marketSideLine = $side === 'home' ? $marketHomeLine : -$marketHomeLine;
        $modelSideLine = $side === 'home' ? $modelHomeLine : -$modelHomeLine;
        $noEdge = $edgePoints <= 0;
        $blocked = $executable === null || in_array('unverified_pregame_snapshot', $assessment['risk_flags'], true) || in_array('stale_prediction', $assessment['risk_flags'], true)
            || in_array('home_quarterback_unresolved', $assessment['risk_flags'], true)
            || in_array('away_quarterback_unresolved', $assessment['risk_flags'], true)
            || in_array('market_not_confirmed_pregame', $assessment['risk_flags'], true);
        $decisionStatus = $noEdge ? 'no_edge' : ($isPlayable ? 'validated' : ($blocked ? 'blocked' : 'provisional'));
        $action = $isPlayable ? 'Bet' : 'Lean';
        $riskFlags = array_values(array_unique([
            ...$assessment['risk_flags'],
            ...($edgePoints < $minimumEdge ? ['point_edge_below_threshold'] : []),
            ...($edgePoints >= (float) config('cfb.predictions.spread_value.extreme_edge_points', 14.0)
                ? ['extreme_model_market_disagreement']
                : []),
        ]));

        $assessment['risk_flags'] = $riskFlags;
        $decisionNotes = $this->decisionNotes($riskFlags);

        return [
            'decision_policy_version' => self::DECISION_POLICY_VERSION,
            'decision_status' => $decisionStatus,
            'decision_summary' => $noEdge ? 'No directional edge at the selected line.'
                : sprintf('%s %s — %s. Model edge %+.1f points.', $teamLabel, $this->formatLine($marketSideLine),
                    $isPlayable ? 'validated model bet' : ($blocked ? 'selection blocked' : 'provisional lean'), $edgePoints),
            'decision_notes' => $decisionNotes,
            'spread_assessment' => $assessment,
            'market_confirmation' => $confirmation,
            'cover_probability_evidence' => $probability,
            'has_playable_value' => $isPlayable,
            'play_count' => $isPlayable ? 1 : 0,
            'best' => [
                'type' => 'spread',
                'label' => $noEdge ? 'No directional edge' : sprintf('%s %s %s to cover', $action, $teamLabel, $this->formatLine($marketSideLine)),
                'side' => $side,
                'edge' => $edgePoints,
                'market_line' => round($marketSideLine, 1),
                'price' => $executable['price'] ?? null,
                'bookmaker' => $executable['bookmaker'] ?? null,
                'market_quote_id' => $executable['quote_id'] ?? null,
                'cover_probability' => $probability['cover_probability'],
                'expected_value_per_unit' => $probability['expected_value_per_unit'],
                'model_line' => round($modelSideLine, 1),
                'market_home_line' => round($marketHomeLine, 1),
                'model_home_line' => round($modelHomeLine, 1),
                'grade' => $isKeyEdge ? 'Key' : ($isPlayable ? 'Playable' : ($noEdge ? 'No edge' : ($blocked ? 'Blocked' : 'Provisional'))),
                'risk_level' => $riskFlags === [] ? 'low' : 'medium',
                'is_key_edge' => $isKeyEdge,
                'stats_supported' => $support['supported'],
                'reason' => $this->reason(
                    $game,
                    $side,
                    $modelHomeLine,
                    $marketHomeLine,
                    $edgePoints,
                    $support['supported'],
                ),
                'risk_flags' => $riskFlags,
                'statistical_support' => $support,
                'market_evidence' => [
                    'source' => 'market_quotes_executable_multibook',
                    'reference_consensus_source' => $market['source'] ?? null,
                    'captured_at' => $executable['captured_at'] ?? null,
                    'observed_at' => $marketHealth['observed_at'],
                    'book_count' => $confirmation['confirming_book_count'],
                    'executable_quote' => $executable,
                    'confirmation' => $confirmation,
                    'bookmaker_home_line_range' => $confirmation['line_range'] ?? null,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function statisticalSupport(CanonicalPrediction $prediction): array
    {
        $inputs = (array) ($prediction->calculationRun?->inputSnapshot?->inputs ?? []);
        $quality = CfbPredictionInputQuality::assess($inputs);
        $diagnostics = (array) ($prediction->calculationRun?->diagnostics ?? []);
        $homeMetrics = (array) data_get($inputs, 'home.metrics', []);
        $awayMetrics = (array) data_get($inputs, 'away.metrics', []);
        $homeSample = (int) data_get($homeMetrics, 'wins', 0) + (int) data_get($homeMetrics, 'losses', 0);
        $awaySample = (int) data_get($awayMetrics, 'wins', 0) + (int) data_get($awayMetrics, 'losses', 0);
        $minimumSample = (int) config('cfb.predictions.spread_value.minimum_sample_games', 6);
        $minimumReliability = (float) config('cfb.predictions.spread_value.minimum_metric_reliability', 0.75);
        $reliability = (float) data_get($diagnostics, 'metric_reliability', 0.0);
        $defaultElo = (float) config('cfb.elo.default_rating', 1500);
        $homeElo = $this->number(data_get($inputs, 'home.elo')) ?? $defaultElo;
        $awayElo = $this->number(data_get($inputs, 'away.elo')) ?? $defaultElo;
        $hasDifferentiatedElo = abs($homeElo - $defaultElo) >= 0.001
            || abs($awayElo - $defaultElo) >= 0.001;
        $hasBidirectionalMetricSample = $homeSample > 0 && $awaySample > 0;
        $modelInputsQualified = array_diff($quality['risk_flags'], ['home_quarterback_unresolved', 'away_quarterback_unresolved']) === [] && ($hasDifferentiatedElo
            || $hasBidirectionalMetricSample);
        $riskFlags = $quality['risk_flags'];

        if (! $modelInputsQualified) {
            $riskFlags[] = 'missing_model_inputs';
        }

        $snapshot = $prediction->calculationRun?->inputSnapshot;
        $early = app(CfbEarlySeasonSpreadSupport::class)->assess(
            $inputs, $diagnostics, $snapshot?->captured_at, (string) $snapshot?->pregame_safety_status,
        );
        if (! $early['eligible'] && ($homeSample < $minimumSample || $awaySample < $minimumSample || $reliability < $minimumReliability)) {
            // Explain the failed prior-season path instead of mislabeling personnel gaps as sample shortages.
            $riskFlags = [...$riskFlags, ...array_diff($early['risk_flags'], ['early_season_exception_not_needed'])];
            if ($homeSample >= $minimumSample && $awaySample >= $minimumSample && $reliability < $minimumReliability) {
                $riskFlags[] = 'insufficient_metric_reliability';
            }
        }
        $riskFlags = array_values(array_unique($riskFlags));

        return [
            'supported' => $riskFlags === [],
            'model_inputs_qualified' => $modelInputsQualified,
            'support_path' => $riskFlags === [] ? ($early['eligible'] ? $early['path'] : 'current_season_sample') : 'insufficient_evidence',
            'early_season_support' => $early,
            'home_sample_games' => $homeSample,
            'away_sample_games' => $awaySample,
            'minimum_sample_games' => $minimumSample,
            'metric_reliability' => round($reliability, 3),
            'minimum_metric_reliability' => $minimumReliability,
            'home_record_season' => $this->integer(data_get($homeMetrics, 'record_season')),
            'away_record_season' => $this->integer(data_get($awayMetrics, 'record_season')),
            'risk_flags' => $riskFlags,
        ];
    }

    private function reason(Game $game, string $side, float $modelHomeLine, float $marketHomeLine, float $edgePoints, bool $supported): string
    {
        $home = $game->homeTeam?->abbreviation ?: $game->homeTeam?->school ?: 'Home';
        $away = $game->awayTeam?->abbreviation ?: $game->awayTeam?->school ?: 'Away';
        $candidate = $side === 'home' ? $home : $away;
        $support = $supported
            ? 'The stored evidence clears the current-season or prior-season-plus-rating support checks.'
            : 'This is a directional estimate; the listed evidence gaps limit betting eligibility.';

        return sprintf(
            'Model: %s %s; market: %s %s. %s has a %.1f-point ATS edge. %s',
            $home,
            $this->formatLine($modelHomeLine),
            $home,
            $this->formatLine($marketHomeLine),
            $candidate,
            $edgePoints,
            $support,
        );
    }

    private function decisionNotes(array $flags): array
    {
        $notes = [];
        foreach ($flags as $flag) {
            if (preg_match('/^(home|away)_personnel_(.+)_unverified$/', $flag, $parts)) {
                $component = match ($parts[2]) {
                    'head_coach' => 'head-coach continuity', 'coverage' => 'personnel coverage',
                    default => str_replace('_', ' ', $parts[2]),
                };
                $notes[] = ucfirst($parts[1]).' '.$component.' is unverified.';

                continue;
            }
            $notes[] = match ($flag) {
                'spread_calibration_unavailable', 'spread_calibration_incompatible', 'spread_calibration_out_of_domain', 'spread_calibration_line_bucket_unvalidated' => 'Cover probability and expected return are not validated.',
                'point_edge_below_threshold' => 'Edge is below the betting threshold.',
                'insufficient_same_side_book_confirmation' => 'Fewer than two books clear the betting edge threshold.',
                'insufficient_fresh_priced_books' => 'Fewer than two fresh priced books are available.',
                'spread_price_unavailable' => 'No eligible fresh price is available.',
                'home_quarterback_evidence_missing', 'away_quarterback_evidence_missing', 'home_quarterback_evidence_unresolved', 'away_quarterback_evidence_unresolved' => 'Current quarterback availability needs verification.',
                default => ucfirst(str_replace('_', ' ', $flag)).'.',
            };
        }

        return array_values(array_unique($notes));
    }

    private function formatLine(float $line): string
    {
        $formatted = rtrim(rtrim(number_format(abs($line), 1, '.', ''), '0'), '.');

        return ($line >= 0 ? '+' : '-').$formatted;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
