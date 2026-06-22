<?php

namespace App\Services\MLB\Signals;

use App\Services\MLB\Picks\MlbPickCandidateData;

class MlbSignalDriverService
{
    /**
     * @return array<string,mixed>
     */
    public function forCandidate(MlbPickCandidateData $candidate): array
    {
        $groups = array_values(array_filter([
            $this->moundGroup($candidate),
            $this->lineupGroup($candidate),
            $this->marketGroup($candidate),
            $this->runEnvironmentGroup($candidate),
            $this->bullpenGroup($candidate),
            $this->riskGroup($candidate),
        ]));

        $scoreDelta = array_sum(array_map(
            fn (array $group): int => (int) ($group['score_delta'] ?? 0),
            $groups
        ));

        $riskFlags = array_values(array_unique(array_merge(
            $candidate->riskFlags,
            ...array_map(fn (array $group): array => (array) ($group['risk_flags'] ?? []), $groups),
        )));
        $reasonCodes = array_values(array_unique(array_merge(
            $candidate->reasonCodes,
            ...array_map(fn (array $group): array => (array) ($group['reason_codes'] ?? []), $groups),
        )));

        return [
            'version' => 'mlb_signal_driver_v1',
            'pregame_safe' => $this->pregameSafe($riskFlags),
            'recommended_angle' => $this->recommendedAngle($candidate, $groups, $riskFlags),
            'score_delta' => max(-20, min(12, $scoreDelta)),
            'reason_codes' => $reasonCodes,
            'risk_flags' => $riskFlags,
            'signal_groups' => $groups,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function moundGroup(MlbPickCandidateData $candidate): ?array
    {
        $codes = $this->codes($candidate);
        $risks = $this->risks($candidate);
        $drivers = [];
        $scoreDelta = 0;
        $status = 'neutral';
        $summary = 'Starter context is limited.';

        if ($this->hasAny($codes, ['probable_pitchers_confirmed', 'starter_confirmed'])) {
            $drivers[] = $this->driver('starter_confirmed', 'Probable starters are confirmed', 'positive', 'ESPN game details');
            $scoreDelta += 3;
            $status = 'positive';
            $summary = 'Confirmed starters support the read.';
        }

        if ($this->hasAny($codes, ['f5_pitcher_edge', 'pitcher_margin_support'])) {
            $drivers[] = $this->driver('starter_edge', 'Starter edge is strongest early', 'positive', 'Prediction model');
            $scoreDelta += 4;
            $status = 'positive';
            $summary = str_contains($candidate->marketType, 'first_')
                ? 'Starter edge supports this early-game angle.'
                : 'Starter edge may be stronger early than full game.';
        }

        if ($this->hasAny($risks, ['unconfirmed_pitcher', 'starter_unconfirmed', 'pitcher_uncertainty', 'pitcher_changed'])) {
            $drivers[] = $this->driver('starter_uncertainty', 'Starting pitcher situation is uncertain', 'risk', 'ESPN game details', false, ['starter_not_confirmed']);
            $scoreDelta -= 8;
            $status = 'risk';
            $summary = 'Starter uncertainty should hold this as tracking only.';
        }

        return $drivers === [] ? null : $this->group('mound', 'Mound', $status, $summary, $scoreDelta, $drivers);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lineupGroup(MlbPickCandidateData $candidate): ?array
    {
        $codes = $this->codes($candidate);
        $risks = $this->risks($candidate);
        $drivers = [];
        $scoreDelta = 0;
        $status = 'neutral';
        $summary = 'Lineup-specific data is limited.';

        if ($this->hasAny($codes, ['f5_offense_split_edge', 'matchup_support', 'season_rate_support'])) {
            $drivers[] = $this->driver('lineup_matchup_support', 'Lineup or matchup context supports the angle', 'positive', 'Prediction model');
            $scoreDelta += 2;
            $status = 'positive';
            $summary = 'Offense and matchup context support the angle.';
        }

        if ($this->hasAny($risks, ['lineup_not_confirmed', 'bench_lineup_flag', 'platoon_data_missing'])) {
            $drivers[] = $this->driver('lineup_not_confirmed', 'Confirmed lineup data is missing', 'warning', 'Lineup feed', false, ['lineup_not_confirmed']);
            $scoreDelta -= 6;
            $status = $status === 'positive' ? 'warning' : 'risk';
            $summary = 'Lineup confirmation is still needed.';
        }

        return $drivers === [] ? null : $this->group('lineup', 'Lineup', $status, $summary, $scoreDelta, $drivers);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function marketGroup(MlbPickCandidateData $candidate): ?array
    {
        $codes = $this->codes($candidate);
        $risks = $this->risks($candidate);
        $drivers = [];
        $scoreDelta = 0;
        $status = 'neutral';
        $summary = 'Market context is limited.';

        if ($this->hasAny($codes, ['model_market_agrees', 'f5_market_agreement'])) {
            $drivers[] = $this->driver('model_market_agreement', 'Model and market point to the same side', 'positive', 'Odds API');
            $scoreDelta += 4;
            $status = 'positive';
            $summary = 'Model and market are aligned.';
        }

        if ($this->hasAny($codes, ['positive_no_vig_edge', 'line_value'])) {
            $drivers[] = $this->driver('positive_no_vig_edge', 'Positive edge after removing sportsbook vig', 'positive', 'Odds API');
            $scoreDelta += 4;
            $status = 'positive';
            $summary = 'Price supports the projection.';
        }

        if ($this->hasAny($codes, ['blend_probability_strong', 'blend_probability_supports_side', 'blend_supports_side'])) {
            $drivers[] = $this->driver('blend_support', 'Blended model-market read supports the angle', 'positive', 'Internal blend');
            $scoreDelta += 2;
            $status = 'positive';
        }

        if ($this->hasAny($codes, ['model_market_disagrees'])) {
            $drivers[] = $this->driver('model_market_disagreement', 'Model and market disagree', 'warning', 'Odds API');
            $scoreDelta -= 8;
            $status = 'warning';
            $summary = 'Market disagreement adds caution.';
        }

        if ($this->hasAny($risks, ['stale_odds', 'stale_price', 'missing_no_vig', 'missing_market_context', 'moneyline_price_missing', 'missing_odds_timestamp'])) {
            $drivers[] = $this->driver('price_quality_risk', 'Price quality is incomplete or stale', 'risk', 'Odds API', false, ['market_timestamp_or_price_missing']);
            $scoreDelta -= 8;
            $status = 'risk';
            $summary = 'Market price needs validation.';
        }

        return $drivers === [] ? null : $this->group('market', 'Market', $status, $summary, $scoreDelta, $drivers);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function runEnvironmentGroup(MlbPickCandidateData $candidate): ?array
    {
        $codes = $this->codes($candidate);
        $risks = $this->risks($candidate);
        $drivers = [];
        $scoreDelta = 0;
        $status = 'neutral';
        $summary = 'Run environment context is limited.';

        if ($this->hasAny($codes, ['weather_supports_over', 'weather_supports_under', 'park_support'])) {
            $drivers[] = $this->driver('park_weather_support', 'Park and weather support this total context', 'positive', 'Weather and venue context');
            $scoreDelta += 3;
            $status = 'positive';
            $summary = 'Park and weather support the angle.';
        }

        if ($this->hasAny($codes, ['model_total_over_market', 'model_total_under_market'])) {
            $drivers[] = $this->driver('model_total_gap', 'Model total differs from the market number', 'positive', 'Prediction model');
            $scoreDelta += 2;
            $status = 'positive';
            $summary = 'Model total creates a trackable gap.';
        }

        if ($this->hasAny($risks, ['weather_missing', 'roof_unknown', 'rain_delay_risk', 'total_model_over_bias', 'low_total_edge'])) {
            $drivers[] = $this->driver('run_environment_risk', 'Run environment or totals calibration adds risk', 'warning', 'Weather and model audit', ! $this->hasAny($risks, ['weather_missing', 'roof_unknown']), $this->hasAny($risks, ['weather_missing', 'roof_unknown']) ? ['weather_or_roof_missing'] : []);
            $scoreDelta -= 5;
            $status = $status === 'positive' ? 'warning' : 'risk';
            $summary = 'Totals context needs caution.';
        }

        return $drivers === [] ? null : $this->group('run_environment', 'Run Environment', $status, $summary, $scoreDelta, $drivers);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function bullpenGroup(MlbPickCandidateData $candidate): ?array
    {
        $codes = $this->codes($candidate);
        $risks = $this->risks($candidate);
        $drivers = [];
        $scoreDelta = 0;
        $status = 'neutral';
        $summary = 'Bullpen context is limited.';

        if ($this->hasAny($codes, ['bullpen_advantage', 'bullpen_margin_support', 'bullpen_quality_home_edge'])) {
            $drivers[] = $this->driver('bullpen_advantage', 'Bullpen quality supports the angle', 'positive', 'Bullpen rating service');
            $scoreDelta += 3;
            $status = 'positive';
            $summary = 'Bullpen profile supports the full-game angle.';
        }

        if ($this->hasAny($codes, ['bullpen_fatigue_over_context'])) {
            $drivers[] = $this->driver('bullpen_fatigue_total', 'Bullpen fatigue supports late scoring risk', 'warning', 'Bullpen rating service');
            $scoreDelta += str_contains($candidate->marketType, 'total') ? 2 : -2;
            $status = 'warning';
            $summary = 'Bullpen fatigue affects full-game risk.';
        }

        if ($this->hasAny($risks, ['bullpen_full_game_risk', 'bullpen_data_stale', 'high_leverage_relievers_unavailable'])) {
            $drivers[] = $this->driver('bullpen_full_game_risk', 'Late-inning bullpen risk is elevated', 'risk', 'Bullpen rating service', false, ['bullpen_context_risk']);
            $scoreDelta -= 6;
            $status = 'risk';
            $summary = 'F5 may be cleaner than full game.';
        }

        return $drivers === [] ? null : $this->group('bullpen', 'Bullpen', $status, $summary, $scoreDelta, $drivers);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function riskGroup(MlbPickCandidateData $candidate): ?array
    {
        $risks = array_values(array_unique($candidate->riskFlags));
        if ($risks === []) {
            return null;
        }

        $drivers = array_map(
            fn (string $risk): array => $this->driver($risk, $this->label($risk), 'risk', 'Candidate guardrail', ! $this->safetyBlockingRisk($risk), $this->safetyBlockingRisk($risk) ? [$risk] : []),
            array_slice($risks, 0, 6)
        );

        return $this->group(
            'risk',
            'Risk',
            'risk',
            'One or more guardrails require caution.',
            -min(10, count($risks) * 2),
            $drivers,
            [],
            $risks
        );
    }

    /**
     * @param  list<array<string,mixed>>  $drivers
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $riskFlags
     * @return array<string,mixed>
     */
    private function group(string $key, string $label, string $status, string $summary, int $scoreDelta, array $drivers, array $reasonCodes = [], array $riskFlags = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'summary' => $summary,
            'score_delta' => $scoreDelta,
            'reason_codes' => array_values(array_unique(array_merge($reasonCodes, array_column($drivers, 'key')))),
            'risk_flags' => $riskFlags,
            'drivers' => $drivers,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function driver(string $key, string $label, string $impact, string $source, bool $pregameSafe = true, array $pregameSafetyReasons = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => null,
            'impact' => $impact,
            'source' => $source,
            'source_timestamp' => null,
            'captured_at' => null,
            'game_start_at' => null,
            'is_pregame_safe' => $pregameSafe,
            'pregame_safety_reasons' => $pregameSafetyReasons,
        ];
    }

    /**
     * @return list<string>
     */
    private function codes(MlbPickCandidateData $candidate): array
    {
        return array_values(array_unique(array_map('strval', $candidate->reasonCodes)));
    }

    /**
     * @return list<string>
     */
    private function risks(MlbPickCandidateData $candidate): array
    {
        return array_values(array_unique(array_map('strval', $candidate->riskFlags)));
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     */
    private function hasAny(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }

    /**
     * @param  list<string>  $riskFlags
     */
    private function pregameSafe(array $riskFlags): bool
    {
        foreach ($riskFlags as $risk) {
            if ($this->safetyBlockingRisk((string) $risk)) {
                return false;
            }
        }

        return true;
    }

    private function safetyBlockingRisk(string $risk): bool
    {
        return in_array($risk, [
            'point_in_time_unsafe',
            'live_only_or_postgame_unsafe',
            'pitcher_changed',
            'starter_changed_after_prediction',
            'odds_after_first_pitch',
            'postponed_suspended_cancelled',
            'missing_game_start_time',
        ], true);
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @param  list<string>  $riskFlags
     */
    private function recommendedAngle(MlbPickCandidateData $candidate, array $groups, array $riskFlags): string
    {
        if (! $this->pregameSafe($riskFlags)) {
            return 'tracking_only';
        }

        if (str_contains($candidate->marketType, 'first_5')) {
            return 'first_5';
        }

        if (str_contains($candidate->marketType, 'first_3')) {
            return 'first_3';
        }

        if ($candidate->marketType === 'player_prop') {
            return 'player_prop';
        }

        $hasMoundEdge = collect($groups)->contains(fn (array $group): bool => $group['key'] === 'mound' && ($group['status'] ?? null) === 'positive');
        $hasBullpenRisk = collect($groups)->contains(fn (array $group): bool => $group['key'] === 'bullpen' && in_array(($group['status'] ?? null), ['warning', 'risk'], true));

        if ($hasMoundEdge && $hasBullpenRisk) {
            return 'first_5';
        }

        return 'full_game';
    }

    private function label(string $code): string
    {
        return str($code)->replace('_', ' ')->title()->toString();
    }
}
