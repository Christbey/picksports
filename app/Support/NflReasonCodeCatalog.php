<?php

namespace App\Support;

class NflReasonCodeCatalog
{
    /**
     * @return list<string>
     */
    public function backgroundCodes(): array
    {
        return [
            'adaptive_calibration_signal',
            'adaptive_point_calibration_signal',
            'bend_dont_break_defense',
            'contextual_adjustments',
            'high_trust_no_market_edge',
            'home_away_split_signal',
            'high_market_total',
            'low_market_total',
            'multi_factor_confluence',
            'new_head_coach_context',
            'ol_dl_matchup_signal',
            'primetime_total_watch',
            'recent_matchup_record_context',
            'rolling_efficiency_mature_sample',
            'rolling_efficiency_signal',
            'same_week_record_context',
            'slow_pace_under_signal',
            'total_key_number_context',
            'total_weather_suppression',
            'week_1_total_uncertainty',
            'weather_total_context',
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return array<string,array<string,mixed>>
     */
    public function metadataForCodes(array $codes): array
    {
        $metadata = [];

        foreach (array_values(array_unique($codes)) as $code) {
            $metadata[$code] = $this->metadata($code);
        }

        return $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(string $code): array
    {
        $source = $this->source($code);
        $marketType = $this->marketType($code);
        $direction = $this->direction($code);
        $diagnostic = $this->isDiagnostic($code);
        $actionable = $this->isActionable($code, $source, $diagnostic);

        return [
            'code' => $code,
            'label' => $this->label($code),
            'source' => $source,
            'market_type' => $marketType,
            'direction' => $direction,
            'is_actionable' => $actionable,
            'is_diagnostic' => $diagnostic,
            'requires_market' => $this->requiresMarket($code),
        ];
    }

    public function isBackground(string $code): bool
    {
        return in_array($code, $this->backgroundCodes(), true);
    }

    protected function source(string $code): string
    {
        if (
            str_contains($code, 'market')
            || str_contains($code, 'line_move')
            || str_contains($code, 'key_number')
            || str_contains($code, 'clv')
            || str_contains($code, 'teaser')
            || str_contains($code, 'steam')
            || str_contains($code, 'slow_book')
            || str_contains($code, 'dead_number')
            || str_contains($code, 'buyback')
            || str_contains($code, 'teaser_corridor')
        ) {
            return 'market';
        }

        if (str_contains($code, 'turnover_luck') || str_contains($code, 'overperformance') || str_contains($code, 'one_score_regression')) {
            return 'regression';
        }

        if (str_contains($code, 'efficiency_mismatch') || str_contains($code, 'success_rate')) {
            return 'model';
        }

        if (str_contains($code, 'weather') || str_contains($code, 'outdoor_total_proxy') || str_contains($code, 'wind_') || str_contains($code, 'rain_') || str_contains($code, 'snow_')) {
            return str_contains($code, '_proxy') ? 'weather_proxy' : 'actual_weather';
        }

        if (str_contains($code, 'qb_') || str_contains($code, 'passing_game') || str_contains($code, 'explosive_pass')) {
            return 'quarterback';
        }

        if (str_contains($code, 'trench') || str_starts_with($code, 'ol_') || str_starts_with($code, 'dl_') || str_contains($code, 'pressure')) {
            return 'trenches';
        }

        if (str_contains($code, 'injury') || str_contains($code, '_out_') || str_contains($code, 'depth') || str_contains($code, 'replacement_value')) {
            return 'injury';
        }

        if (str_contains($code, 'coach')) {
            return 'coaching';
        }

        if (str_contains($code, 'rest') || str_contains($code, 'travel') || str_contains($code, 'division') || str_contains($code, 'conference') || str_contains($code, 'primetime') || str_contains($code, 'matchup_record') || str_contains($code, 'same_week') || str_contains($code, 'h2h_record')) {
            return 'schedule_context';
        }

        if (str_contains($code, 'calibration')) {
            return 'calibration';
        }

        if (str_contains($code, 'rolling_efficiency') || str_contains($code, 'opponent_adjusted') || str_contains($code, 'model_signal')) {
            return 'model';
        }

        return 'model_context';
    }

    protected function marketType(string $code): ?string
    {
        if (str_contains($code, 'total') || str_contains($code, '_under_') || str_contains($code, '_over_')) {
            return 'total';
        }

        if (str_contains($code, 'spread') || str_contains($code, 'cover') || str_contains($code, 'key_number') || str_contains($code, 'teaser')) {
            return 'spread';
        }

        if (str_contains($code, 'moneyline') || str_contains($code, 'winner') || str_contains($code, 'model_side')) {
            return 'moneyline';
        }

        if (str_contains($code, 'futures') || str_contains($code, 'super_bowl')) {
            return 'futures';
        }

        return null;
    }

    protected function direction(string $code): ?string
    {
        if (str_contains($code, '_home') || str_ends_with($code, '_home_edge') || str_ends_with($code, '_home')) {
            return 'home';
        }

        if (str_contains($code, '_away') || str_ends_with($code, '_away_edge') || str_ends_with($code, '_away')) {
            return 'away';
        }

        if (str_contains($code, '_under') || str_ends_with($code, '_under_signal')) {
            return 'under';
        }

        if (str_contains($code, '_over') || str_ends_with($code, '_over_signal')) {
            return 'over';
        }

        return null;
    }

    protected function isDiagnostic(string $code): bool
    {
        if ($this->isBackground($code)) {
            return true;
        }

        return str_contains($code, 'risk')
            || str_contains($code, 'uncertainty')
            || str_contains($code, 'small_sample')
            || str_contains($code, 'missing_')
            || str_contains($code, 'no_market')
            || str_contains($code, 'proxy')
            || str_contains($code, 'stale_')
            || str_contains($code, 'negative_clv')
            || str_contains($code, 'dead_number_tax')
            || str_contains($code, 'overreaction');
    }

    protected function isActionable(string $code, string $source, bool $diagnostic): bool
    {
        if ($diagnostic) {
            return false;
        }

        if (in_array($source, ['market', 'quarterback', 'trenches', 'injury', 'actual_weather', 'schedule_context', 'coaching'], true)) {
            return true;
        }

        return in_array($code, ['strong_model_signal', 'lean_model_signal', 'bettable_confluence'], true);
    }

    protected function requiresMarket(string $code): bool
    {
        return str_contains($code, 'market')
            || str_contains($code, 'key_number')
            || str_contains($code, 'line_move')
            || str_contains($code, 'teaser')
            || str_contains($code, 'clv')
            || str_contains($code, 'steam')
            || str_contains($code, 'dead_number')
            || str_contains($code, 'buyback')
            || str_contains($code, 'slow_book')
            || str_contains($code, 'bettable');
    }

    protected function label(string $code): string
    {
        return str($code)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }
}
