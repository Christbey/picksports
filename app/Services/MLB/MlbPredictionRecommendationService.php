<?php

namespace App\Services\MLB;

use App\Models\MLB\Prediction;
use App\Support\MLB\MlbGamePhase;
use Illuminate\Support\Carbon;

class MlbPredictionRecommendationService
{
    public function __construct(
        private readonly MlbBettingSignalService $signals,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function forPrediction(Prediction $prediction): array
    {
        $prediction->loadMissing(['game.homeTeam', 'game.awayTeam']);

        $game = $prediction->game;
        $phase = $this->predictionPhase((string) ($game?->status ?? ''));
        $candidates = $this->signals->betCandidatesForPrediction(
            $prediction,
            enabledOnly: true,
            includePasses: true,
        );
        $primaryCandidate = $this->primaryCandidate($candidates);
        $candidateRecommendation = $this->normalizeCandidate($primaryCandidate, 'pregame', $prediction, applyCalibrationGuard: false);
        $publicPregameRecommendation = $this->normalizeCandidate($primaryCandidate, 'pregame', $prediction, applyCalibrationGuard: true);
        $allCandidates = array_map(
            fn (array $candidate): array => $this->normalizeCandidate($candidate, 'pregame', $prediction, applyCalibrationGuard: false),
            $candidates,
        );

        if ($phase === MlbGamePhase::LIVE) {
            return $this->recommendationEnvelope(
                $this->monitorRecommendation($prediction, $phase),
                $candidateRecommendation,
                $allCandidates,
                $prediction
            );
        }

        if ($phase === MlbGamePhase::FINAL) {
            return $this->recommendationEnvelope(
                $this->noPlayRecommendation($prediction, $phase, 'final_result_context'),
                $candidateRecommendation,
                $allCandidates,
                $prediction
            );
        }

        if (in_array($phase, [MlbGamePhase::DELAYED, MlbGamePhase::POSTPONED, MlbGamePhase::SUSPENDED, MlbGamePhase::CANCELLED, MlbGamePhase::UNKNOWN], true)) {
            return $this->recommendationEnvelope(
                $this->noPlayRecommendation($prediction, $phase, "game_phase_{$phase}"),
                $candidateRecommendation,
                $allCandidates,
                $prediction
            );
        }

        return $this->recommendationEnvelope(
            $publicPregameRecommendation,
            $candidateRecommendation,
            $allCandidates,
            $prediction
        );
    }

    public function rawImpliedProbabilityForAmericanOdds(int $price): ?float
    {
        return $this->signals->americanToImpliedProbability($price);
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<string,mixed>|null
     */
    private function primaryCandidate(array $candidates): ?array
    {
        foreach (['bet', 'lean', 'pass'] as $classification) {
            foreach ($candidates as $candidate) {
                if (($candidate['classification'] ?? null) === $classification) {
                    return $candidate;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param  array<string,mixed>|null  $candidate
     * @return array<string,mixed>
     */
    private function normalizeCandidate(?array $candidate, string $phase, Prediction $prediction, bool $applyCalibrationGuard = true): array
    {
        if ($candidate === null) {
            return $this->emptyRecommendation($phase);
        }

        $classification = (string) ($candidate['classification'] ?? 'pass');
        $score = is_numeric($candidate['score'] ?? null) ? (int) $candidate['score'] : null;
        $reasonCodes = array_values((array) ($candidate['reason_codes'] ?? []));
        $riskFlags = array_values((array) ($candidate['risk_flags'] ?? []));
        $noBetReason = $candidate['no_bet_reason'] ?? null;

        $blockReasons = [];

        if ($applyCalibrationGuard && $this->calibrationGuardBlocksPromotion($prediction, $phase, $classification)) {
            $classification = 'pass';
            $riskFlags[] = 'recommendation_calibration_unvalidated';
            $reasonCodes[] = 'recommendation_calibration_guard';
            $blockReasons[] = 'recommendation_calibration_unvalidated';
            $noBetReason = 'recommendation_calibration_unvalidated';
        }

        if ($applyCalibrationGuard
            && $phase === 'pregame'
            && in_array($classification, ['bet', 'lean'], true)
            && in_array('model_market_disagreement_unvalidated', $riskFlags, true)) {
            $classification = 'pass';
            $reasonCodes[] = 'model_market_disagreement_guard';
            $blockReasons[] = 'model_market_disagreement_unvalidated';
            $noBetReason = 'model_market_disagreement_unvalidated';
        }

        $recommendationType = match ($classification) {
            'bet' => 'bet',
            'lean' => 'lean',
            default => 'no_play',
        };

        return [
            'recommendation_type' => $recommendationType,
            'market_type' => $candidate['type'] ?? null,
            'recommendation_strength' => $this->recommendationStrength($classification, $score),
            'is_bet' => $phase === 'pregame' && $classification === 'bet',
            'is_visible' => true,
            'prediction_phase' => $phase,
            'pick_side' => $candidate['pick_side'] ?? null,
            'team_id' => $candidate['team_id'] ?? null,
            'team_name' => $candidate['team_name'] ?? null,
            'model_probability' => $this->floatOrNull($candidate['model_probability'] ?? $candidate['win_probability'] ?? null),
            'market_price' => $this->intOrNull($candidate['market_price'] ?? null),
            'raw_implied_probability' => $this->floatOrNull($candidate['market_implied_probability'] ?? null),
            'no_vig_implied_probability' => $this->floatOrNull($candidate['no_vig_implied_probability'] ?? null),
            'raw_edge' => $this->floatOrNull($candidate['probability_edge'] ?? null),
            'no_vig_edge' => $this->floatOrNull($candidate['no_vig_edge'] ?? null),
            'score' => $score,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'risk_flags' => array_values(array_unique($riskFlags)),
            'block_reasons' => array_values(array_unique($blockReasons)),
            'no_bet_reason' => $noBetReason,
            'odds_updated_at' => $this->oddsUpdatedAt($prediction),
            'odds_fresh' => $this->oddsFresh($prediction),
        ];
    }

    /**
     * @param  array<string,mixed>  $publicRecommendation
     * @param  array<string,mixed>  $candidateRecommendation
     * @param  array<int,array<string,mixed>>  $allCandidates
     * @return array<string,mixed>
     */
    private function recommendationEnvelope(array $publicRecommendation, array $candidateRecommendation, array $allCandidates, Prediction $prediction): array
    {
        $promotion = $this->promotionStatus($publicRecommendation, $candidateRecommendation, $prediction);

        return [
            ...$publicRecommendation,
            'public' => $publicRecommendation,
            'candidate' => $candidateRecommendation,
            'candidate_recommendation' => $candidateRecommendation,
            'pregame_recommendation' => $candidateRecommendation,
            'promotion' => $promotion,
            'all_candidates' => $allCandidates,
        ];
    }

    /**
     * @param  array<string,mixed>  $publicRecommendation
     * @param  array<string,mixed>  $candidateRecommendation
     * @return array<string,mixed>
     */
    private function promotionStatus(array $publicRecommendation, array $candidateRecommendation, Prediction $prediction): array
    {
        $candidateType = (string) ($candidateRecommendation['recommendation_type'] ?? 'no_play');
        $publicType = (string) ($publicRecommendation['recommendation_type'] ?? 'no_play');
        $phase = (string) ($publicRecommendation['prediction_phase'] ?? 'unknown');
        $promotionsValidated = (bool) config('mlb.signals.bet_filter.promotions_validated', false);
        $calibrationGuardEnabled = (bool) config('mlb.signals.bet_filter.calibration_guard_enabled', true);
        $candidatePromotable = in_array($candidateType, ['bet', 'lean'], true);
        $blockReasons = array_values(array_unique(array_filter([
            ...((array) ($publicRecommendation['block_reasons'] ?? [])),
            ...$this->promotionBlockReasons($publicRecommendation, $candidateRecommendation),
        ])));

        $status = match (true) {
            $phase !== 'pregame' => 'not_applicable',
            ! $candidatePromotable => 'not_applicable',
            $blockReasons !== [] => 'blocked',
            $calibrationGuardEnabled && ! $promotionsValidated => 'blocked',
            default => 'enabled',
        };

        if ($status === 'blocked' && $blockReasons === [] && $calibrationGuardEnabled && ! $promotionsValidated) {
            $blockReasons[] = 'recommendation_calibration_unvalidated';
        }

        return [
            'status' => $status,
            'public_recommendation_type' => $publicType,
            'candidate_recommendation_type' => $candidateType,
            'promotions_validated' => $promotionsValidated,
            'calibration_guard_enabled' => $calibrationGuardEnabled,
            'validated_for_promotion' => $status === 'enabled',
            'block_reasons' => array_values(array_unique($blockReasons)),
            'game_phase' => $this->predictionPhase((string) ($prediction->game?->status ?? '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $publicRecommendation
     * @param  array<string,mixed>  $candidateRecommendation
     * @return list<string>
     */
    private function promotionBlockReasons(array $publicRecommendation, array $candidateRecommendation): array
    {
        $candidateType = (string) ($candidateRecommendation['recommendation_type'] ?? 'no_play');
        if (! in_array($candidateType, ['bet', 'lean'], true)) {
            return [];
        }

        $riskFlags = (array) ($publicRecommendation['risk_flags'] ?? []);
        $reasons = [];

        foreach (['recommendation_calibration_unvalidated', 'model_market_disagreement_unvalidated'] as $riskFlag) {
            if (in_array($riskFlag, $riskFlags, true)) {
                $reasons[] = $riskFlag;
            }
        }

        return $reasons;
    }

    private function calibrationGuardBlocksPromotion(Prediction $prediction, string $phase, string $classification): bool
    {
        if (! in_array($classification, ['bet', 'lean'], true)) {
            return false;
        }

        if ($phase !== 'pregame') {
            return false;
        }

        if (! (bool) config('mlb.signals.bet_filter.calibration_guard_enabled', true)) {
            return false;
        }

        if ((bool) config('mlb.signals.bet_filter.promotions_validated', false)) {
            return false;
        }

        return $this->predictionPhase((string) ($prediction->game?->status ?? '')) === MlbGamePhase::PREGAME;
    }

    /**
     * @return array<string,mixed>
     */
    private function monitorRecommendation(Prediction $prediction, string $phase): array
    {
        $riskFlags = [];
        $updatedAt = $prediction->live_updated_at ? Carbon::parse($prediction->live_updated_at) : null;

        if ($updatedAt === null || $updatedAt->lt(now()->subMinutes((int) config('mlb.signals.live_stale_minutes', 6)))) {
            $riskFlags[] = 'live_model_stale';
        }

        return [
            'recommendation_type' => 'monitor',
            'market_type' => 'live',
            'recommendation_strength' => 'none',
            'is_bet' => false,
            'is_visible' => true,
            'prediction_phase' => $phase,
            'pick_side' => null,
            'team_id' => null,
            'team_name' => null,
            'model_probability' => $prediction->live_win_probability !== null ? round((float) $prediction->live_win_probability, 4) : null,
            'market_price' => null,
            'raw_implied_probability' => null,
            'no_vig_implied_probability' => null,
            'raw_edge' => null,
            'no_vig_edge' => null,
            'score' => null,
            'reason_codes' => ['live_prediction_monitor'],
            'risk_flags' => $riskFlags,
            'no_bet_reason' => 'live_monitor_only',
            'odds_updated_at' => null,
            'odds_fresh' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function noPlayRecommendation(Prediction $prediction, string $phase, string $reason): array
    {
        return [
            ...$this->emptyRecommendation($phase),
            'reason_codes' => [$reason],
            'model_probability' => $prediction->win_probability !== null
                ? round(max((float) $prediction->win_probability, 1 - (float) $prediction->win_probability), 4)
                : null,
            'no_bet_reason' => $reason,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyRecommendation(string $phase): array
    {
        return [
            'recommendation_type' => 'no_play',
            'market_type' => null,
            'recommendation_strength' => 'none',
            'is_bet' => false,
            'is_visible' => true,
            'prediction_phase' => $phase,
            'pick_side' => null,
            'team_id' => null,
            'team_name' => null,
            'model_probability' => null,
            'market_price' => null,
            'raw_implied_probability' => null,
            'no_vig_implied_probability' => null,
            'raw_edge' => null,
            'no_vig_edge' => null,
            'score' => null,
            'reason_codes' => [],
            'risk_flags' => [],
            'block_reasons' => [],
            'no_bet_reason' => 'no_candidate',
            'odds_updated_at' => null,
            'odds_fresh' => false,
        ];
    }

    private function predictionPhase(string $status): string
    {
        return MlbGamePhase::phase($status);
    }

    private function recommendationStrength(string $classification, ?int $score): string
    {
        return match ($classification) {
            'bet' => ($score ?? 0) >= 85 ? 'strong' : 'moderate',
            'lean' => 'lean',
            default => 'none',
        };
    }

    private function oddsUpdatedAt(Prediction $prediction): ?string
    {
        $updatedAt = $prediction?->game?->odds_updated_at;

        return $updatedAt ? Carbon::parse($updatedAt)->toIso8601String() : null;
    }

    private function oddsFresh(Prediction $prediction): bool
    {
        $updatedAt = $this->oddsUpdatedAt($prediction);
        if ($updatedAt === null) {
            return false;
        }

        return Carbon::parse($updatedAt)->gte(now()->subHours((int) config('mlb.signals.odds_stale_hours', 12)));
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
