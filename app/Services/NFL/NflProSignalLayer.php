<?php

namespace App\Services\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;

class NflProSignalLayer
{
    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $analysis
     * @return array<string,mixed>
     */
    public function build(
        Game $game,
        array $metadata,
        array $analysis,
        float $predictedSpread,
        float $predictedTotal,
        float $winProbability
    ): array {
        $marketSpread = $this->number(data_get($analysis, 'calculated_edge.market_spread'));
        $marketTotal = $this->number(data_get($analysis, 'calculated_edge.market_total'));
        $spreadEdge = $this->number(data_get($analysis, 'calculated_edge.spread_points'));
        $totalEdge = $this->number(data_get($analysis, 'calculated_edge.total_points'));
        $reasonCodes = array_values(array_unique((array) ($analysis['reason_codes'] ?? [])));
        $riskFlags = array_values(array_unique((array) ($analysis['risk_flags'] ?? [])));
        $keyNumbers = $this->keyNumbers();
        $nearKeyNumbers = $marketSpread !== null ? $this->nearKeyNumbers($marketSpread, $keyNumbers) : [];
        $crossedKeyNumbers = $marketSpread !== null
            ? $this->crossedKeyNumbers($marketSpread, $predictedSpread, $keyNumbers)
            : [];
        $pickSide = $marketSpread === null ? null : ($predictedSpread >= $marketSpread ? 'home' : 'away');
        $spreadPrice = $pickSide === null ? null : $this->spreadPriceForPick($game, $pickSide);
        $teaserCandidate = in_array(3, $crossedKeyNumbers, true)
            && in_array(7, $crossedKeyNumbers, true)
            && $this->validTeaserPrice($spreadPrice);
        $numberDiscipline = $this->numberDisciplineContext($marketSpread, $marketTotal, $crossedKeyNumbers, $nearKeyNumbers, $spreadPrice, $teaserCandidate);
        $marketMovement = $this->marketMovementContext($game, $pickSide, $marketSpread);
        $injuryReplacement = $this->injuryReplacementContext($metadata);
        $weatherRoof = $this->weatherRoofContext($game, $metadata);
        $efficiencyMismatch = $this->efficiencyMismatchContext($metadata);
        $regressionContext = $this->regressionContext($metadata, $reasonCodes);
        $marketValueReasonCodes = $this->marketValueReasonCodes($spreadEdge, $totalEdge, $crossedKeyNumbers, $teaserCandidate, $marketMovement, $numberDiscipline);
        $footballReasonCodes = $this->footballReasonCodes($metadata, $injuryReplacement, $weatherRoof, $efficiencyMismatch);
        $regressionReasonCodes = $this->regressionReasonCodes($regressionContext, $reasonCodes);
        $riskReasonCodes = $this->riskReasonCodes($metadata, $reasonCodes, $riskFlags, $spreadPrice, $numberDiscipline, $marketMovement);
        $scoreComponents = $this->scoreComponents(
            $spreadEdge,
            $totalEdge,
            $crossedKeyNumbers,
            $nearKeyNumbers,
            $teaserCandidate,
            $marketMovement,
            $numberDiscipline,
            $injuryReplacement,
            $weatherRoof,
            $efficiencyMismatch,
            $regressionContext,
            $metadata,
            $riskFlags,
            $riskReasonCodes
        );
        $score = max(0, min(100, array_sum($scoreComponents)));
        $hasMarketOrNumberValue = $this->hasMarketOrNumberValue($spreadEdge, $totalEdge, $crossedKeyNumbers, $nearKeyNumbers);

        if (! $hasMarketOrNumberValue) {
            if ($score >= 60) {
                $score = 59;
            }

            $riskFlags[] = 'no_market_or_key_number_gate';
            $riskReasonCodes[] = 'no_market_or_key_number_gate';
        }

        $score = (int) round($score);
        $allReasonCodes = array_values(array_unique([
            ...$marketValueReasonCodes,
            ...$footballReasonCodes,
            ...$regressionReasonCodes,
            ...$riskReasonCodes,
        ]));

        return [
            'version' => 'nfl-pro-signal-layer-v1',
            'score' => $score,
            'tier' => $this->tier($score),
            'market_context' => [
                'pick_side' => $pickSide,
                'market_spread' => $marketSpread !== null ? round($marketSpread, 3) : null,
                'model_spread' => round($predictedSpread, 3),
                'spread_edge' => $spreadEdge !== null ? round($spreadEdge, 3) : null,
                'market_total' => $marketTotal !== null ? round($marketTotal, 3) : null,
                'model_total' => round($predictedTotal, 3),
                'total_edge' => $totalEdge !== null ? round($totalEdge, 3) : null,
                'key_numbers' => $keyNumbers,
                'near_key_numbers' => $nearKeyNumbers,
                'crossed_key_numbers' => $crossedKeyNumbers,
                'crossed_3_and_7' => in_array(3, $crossedKeyNumbers, true) && in_array(7, $crossedKeyNumbers, true),
                'teaser_candidate' => $teaserCandidate,
                'spread_price' => $spreadPrice,
                'line_movement_points' => $marketMovement['line_movement_points'],
                'closing_line_value_points' => $marketMovement['closing_line_value_points'],
                'win_probability' => round($winProbability, 6),
            ],
            'number_discipline' => $numberDiscipline,
            'market_movement' => $marketMovement,
            'football_context' => [
                'qb_signal' => $this->number(data_get($metadata, 'qb_form.signal_spread')),
                'line_signal' => $this->number(data_get($metadata, 'line_matchup.signal_spread')),
                'rolling_efficiency_signal' => $this->number(data_get($metadata, 'rolling_efficiency.signal_spread')),
                'weather_total_adjustment' => $this->number(data_get($metadata, 'contextual_factors.weather_total.total_adjustment'))
                    ?? $this->number(data_get($metadata, 'actual_weather.total_adjustment')),
                'division_game' => (bool) data_get($metadata, 'contextual_factors.division_rivalry.is_division_game', false),
                'rest_travel_applied' => (bool) data_get($metadata, 'contextual_factors.schedule_spot.applied', false),
            ],
            'injury_replacement' => $injuryReplacement,
            'weather_roof' => $weatherRoof,
            'efficiency_mismatch' => $efficiencyMismatch,
            'regression_context' => $regressionContext,
            'reason_codes' => $allReasonCodes,
            'risk_flags' => array_values(array_unique($riskFlags)),
            'score_components' => $scoreComponents,
        ];
    }

    /**
     * @return list<int>
     */
    private function keyNumbers(): array
    {
        return collect((array) config('nfl.betting.key_numbers', [3, 5, 7, 10]))
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $keyNumbers
     * @return list<int>
     */
    private function nearKeyNumbers(float $marketSpread, array $keyNumbers): array
    {
        $absMarket = abs($marketSpread);

        return collect($keyNumbers)
            ->filter(fn (int $key): bool => abs($absMarket - $key) <= 0.5)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $keyNumbers
     * @return list<int>
     */
    private function crossedKeyNumbers(float $marketSpread, float $modelSpread, array $keyNumbers): array
    {
        $market = abs($marketSpread);
        $model = abs($modelSpread);

        return collect($keyNumbers)
            ->filter(fn (int $key): bool => ($market < $key && $model >= $key) || ($market > $key && $model <= $key))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function marketValueReasonCodes(?float $spreadEdge, ?float $totalEdge, array $crossedKeyNumbers, bool $teaserCandidate, array $marketMovement, array $numberDiscipline): array
    {
        $codes = [];

        foreach ($crossedKeyNumbers as $keyNumber) {
            $codes[] = 'key_number_edge_'.$keyNumber;
            $codes[] = 'spread_crosses_key_number';
        }

        if ($teaserCandidate) {
            $codes[] = 'teaser_candidate';
        }

        if ((bool) ($numberDiscipline['low_total_key_number_boost'] ?? false)) {
            $codes[] = 'low_total_key_number_boost';
        }

        if ($spreadEdge !== null && abs($spreadEdge) >= 4.0) {
            $codes[] = 'market_overreaction';
        }

        if ((bool) ($marketMovement['steam_freshness'] ?? false)) {
            $codes[] = 'steam_freshness';
        }

        if ((bool) ($marketMovement['market_setter_slow_book_gap'] ?? false)) {
            $codes[] = 'market_setter_slow_book_gap';
        }

        if ((bool) ($marketMovement['buyback_resistance'] ?? false)) {
            $codes[] = 'buyback_resistance';
        }

        $clv = $this->number($marketMovement['closing_line_value_points'] ?? null);
        if ($clv !== null) {
            $codes[] = $clv >= 0 ? 'positive_clv_profile' : 'negative_clv_profile';
        }

        if ($totalEdge !== null && abs($totalEdge) >= 3.0) {
            $codes[] = $totalEdge < 0 ? 'weather_total_suppression' : 'pace_total_pressure';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return list<string>
     */
    private function footballReasonCodes(array $metadata, array $injuryReplacement, array $weatherRoof, array $efficiencyMismatch): array
    {
        $codes = [];

        if (abs((float) data_get($metadata, 'qb_form.signal_spread', 0.0)) >= 1.0) {
            $codes[] = 'qb_status_edge';
        }

        if (abs((float) data_get($metadata, 'line_matchup.signal_spread', 0.0)) >= 1.0) {
            $codes[] = 'trenches_pressure_edge';
        }

        if ((bool) data_get($metadata, 'contextual_factors.schedule_spot.applied', false)) {
            $codes[] = 'rest_travel_edge';
        }

        if ((bool) data_get($metadata, 'contextual_factors.division_rivalry.is_division_game', false)) {
            $codes[] = 'division_dog_key_number';
        }

        if ((bool) ($injuryReplacement['qb_replacement_edge'] ?? false)) {
            $codes[] = 'qb_replacement_value_edge';
        }

        if ((bool) ($injuryReplacement['injury_overreaction_risk'] ?? false)) {
            $codes[] = 'injury_overreaction_risk';
        }

        if ((bool) ($weatherRoof['roof_weather_protected'] ?? false)) {
            $codes[] = 'roof_weather_protected';
        } elseif ((bool) ($weatherRoof['weather_total_suppression'] ?? false)) {
            $codes[] = 'weather_total_suppression';
        }

        if ((bool) ($efficiencyMismatch['epa_edge'] ?? false)) {
            $codes[] = 'efficiency_mismatch_edge';
        }

        if ((bool) ($efficiencyMismatch['pressure_mismatch'] ?? false)) {
            $codes[] = 'trenches_pressure_edge';
        }

        return $codes;
    }

    /**
     * @param  array<string,mixed>  $regressionContext
     * @param  list<string>  $reasonCodes
     * @return list<string>
     */
    private function regressionReasonCodes(array $regressionContext, array $reasonCodes): array
    {
        $codes = [];

        if ((bool) ($regressionContext['turnover_luck_flagged'] ?? false)) {
            $codes[] = 'turnover_luck_fade';
        }

        if ((bool) ($regressionContext['one_score_regression_flagged'] ?? false)) {
            $codes[] = 'one_score_regression_risk';
        }

        if ((bool) ($regressionContext['record_overperformance_flagged'] ?? false)
            || in_array('recent_matchup_record_home_edge', $reasonCodes, true)
            || in_array('recent_matchup_record_away_edge', $reasonCodes, true)) {
            $codes[] = 'record_overperformance_fade';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $riskFlags
     * @return list<string>
     */
    private function riskReasonCodes(array $metadata, array $reasonCodes, array $riskFlags, ?int $spreadPrice, array $numberDiscipline, array $marketMovement): array
    {
        $codes = [];

        if (in_array('low_data_quality', $reasonCodes, true) || in_array('small_sample_warning', $reasonCodes, true)) {
            $codes[] = 'low_data_quality';
        }

        if (in_array('stale_line_edge', $reasonCodes, true)) {
            $codes[] = 'negative_clv_profile';
        }

        if ($spreadPrice !== null && abs($spreadPrice) > 120 || (bool) ($numberDiscipline['dead_number_tax'] ?? false)) {
            $codes[] = 'dead_number_tax';
        }

        if ((bool) ($marketMovement['negative_clv_profile'] ?? false)) {
            $codes[] = 'negative_clv_profile';
        }

        if ((bool) data_get($metadata, 'contextual_factors.division_rivalry.is_division_game', false)
            && ! in_array('division_rivalry', $riskFlags, true)) {
            $codes[] = 'division_rivalry';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string,mixed>
     */
    private function numberDisciplineContext(?float $marketSpread, ?float $marketTotal, array $crossedKeyNumbers, array $nearKeyNumbers, ?int $spreadPrice, bool $teaserCandidate): array
    {
        $deadNumbers = [4, 6, 8, 9];
        $marketAbs = $marketSpread !== null ? abs($marketSpread) : null;
        $deadNumberTax = $marketAbs !== null
            && collect($deadNumbers)->contains(fn (int $dead): bool => abs($marketAbs - $dead) <= 0.5)
            && $spreadPrice !== null
            && abs($spreadPrice) > 115;
        $lowTotalKeyNumberBoost = $marketTotal !== null
            && $marketTotal <= 42.0
            && array_intersect($crossedKeyNumbers, [3, 7]) !== [];
        $teaserCorridorQuality = match (true) {
            ! $teaserCandidate => 'none',
            $marketTotal !== null && $marketTotal <= 42.0 => 'premium',
            $marketTotal !== null && $marketTotal >= 49.0 => 'thin',
            default => 'standard',
        };

        return [
            'dead_numbers' => $deadNumbers,
            'dead_number_tax' => $deadNumberTax,
            'low_total_key_number_boost' => $lowTotalKeyNumberBoost,
            'teaser_corridor_quality' => $teaserCorridorQuality,
            'near_key_numbers' => $nearKeyNumbers,
            'crossed_key_numbers' => $crossedKeyNumbers,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketMovementContext(Game $game, ?string $pickSide, ?float $marketSpread): array
    {
        $snapshots = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->orderBy('captured_at')
            ->get();
        $openSpread = $snapshots->isNotEmpty() ? $this->homeMarginSpread((array) $snapshots->first()?->odds_data) : null;
        $lastSnapshotSpread = $snapshots->isNotEmpty() ? $this->homeMarginSpread((array) $snapshots->last()?->odds_data) : null;
        $currentSpread = $marketSpread ?? $lastSnapshotSpread;
        $lineMovement = $openSpread !== null && $currentSpread !== null ? $currentSpread - $openSpread : null;
        $closingLineValue = $pickSide !== null && $marketSpread !== null && $lastSnapshotSpread !== null && $this->isFinal($game)
            ? $this->spreadClv($pickSide, $marketSpread, $lastSnapshotSpread)
            : null;
        $bookRange = $this->spreadBookRange($game);
        $spanMinutes = null;

        if ($snapshots->count() >= 2) {
            $firstCapturedAt = $snapshots->first()?->captured_at;
            $lastCapturedAt = $snapshots->last()?->captured_at;
            if ($firstCapturedAt && $lastCapturedAt) {
                $spanMinutes = abs($firstCapturedAt->diffInMinutes($lastCapturedAt));
            }
        }

        $midSpread = $snapshots->count() >= 3
            ? $this->homeMarginSpread((array) $snapshots[(int) floor($snapshots->count() / 2)]?->odds_data)
            : null;
        $buybackResistance = $openSpread !== null
            && $midSpread !== null
            && $currentSpread !== null
            && abs($midSpread - $openSpread) >= 1.0
            && abs($currentSpread - $midSpread) >= 0.5
            && (($midSpread - $openSpread) > 0) !== (($currentSpread - $midSpread) > 0);

        return [
            'open_spread' => $openSpread !== null ? round($openSpread, 3) : null,
            'current_spread' => $currentSpread !== null ? round($currentSpread, 3) : null,
            'last_snapshot_spread' => $lastSnapshotSpread !== null ? round($lastSnapshotSpread, 3) : null,
            'line_movement_points' => $lineMovement !== null ? round($lineMovement, 3) : null,
            'closing_line_value_points' => $closingLineValue !== null ? round($closingLineValue, 3) : null,
            'positive_clv_profile' => $closingLineValue !== null && $closingLineValue >= 0,
            'negative_clv_profile' => $closingLineValue !== null && $closingLineValue < 0,
            'steam_freshness' => $lineMovement !== null && abs($lineMovement) >= 1.0 && ($spanMinutes === null || $spanMinutes <= 360),
            'market_setter_slow_book_gap' => $bookRange !== null && $bookRange >= 1.0,
            'buyback_resistance' => $buybackResistance,
            'book_spread_range' => $bookRange !== null ? round($bookRange, 3) : null,
            'snapshot_span_minutes' => $spanMinutes,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function injuryReplacementContext(array $metadata): array
    {
        $spreadAdjustment = $this->number(data_get($metadata, 'depth_chart_injuries.spread_adjustment')) ?? 0.0;
        $homeOut = $this->number(data_get($metadata, 'depth_chart_injuries.home_out_weighted')) ?? 0.0;
        $awayOut = $this->number(data_get($metadata, 'depth_chart_injuries.away_out_weighted')) ?? 0.0;
        $homeQuestionable = $this->number(data_get($metadata, 'depth_chart_injuries.home_questionable_weighted')) ?? 0.0;
        $awayQuestionable = $this->number(data_get($metadata, 'depth_chart_injuries.away_questionable_weighted')) ?? 0.0;

        return [
            'spread_adjustment' => round($spreadAdjustment, 3),
            'home_out_weighted' => round($homeOut, 3),
            'away_out_weighted' => round($awayOut, 3),
            'home_questionable_weighted' => round($homeQuestionable, 3),
            'away_questionable_weighted' => round($awayQuestionable, 3),
            'cluster_weight_delta' => round(($homeOut + $homeQuestionable) - ($awayOut + $awayQuestionable), 3),
            'qb_replacement_edge' => abs($spreadAdjustment) >= 1.5,
            'injury_overreaction_risk' => abs($spreadAdjustment) >= 2.5,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function weatherRoofContext(Game $game, array $metadata): array
    {
        $weather = $game->relationLoaded('weather') ? $game->weather : $game->weather()->first();
        $isIndoor = (bool) ($weather?->is_indoor ?? false) || $this->venueLooksIndoor($game);
        $wind = $this->number($weather?->wind_speed_mph);
        $gust = $this->number($weather?->wind_gust_mph);
        $precipitation = $this->number($weather?->precipitation_inches);
        $temperature = $this->number($weather?->temperature_f);
        $metadataAdjustment = $this->number(data_get($metadata, 'actual_weather.total_adjustment'))
            ?? $this->number(data_get($metadata, 'contextual_factors.weather_total.total_adjustment'))
            ?? 0.0;
        $weatherTotalSuppression = ! $isIndoor && (
            abs($metadataAdjustment) >= 1.0
            || ($wind !== null && $wind >= (float) config('nfl.predictions.actual_weather.wind_under_threshold_mph', 15))
            || ($gust !== null && $gust >= (float) config('nfl.predictions.actual_weather.gust_under_threshold_mph', 24))
            || ($precipitation !== null && $precipitation >= (float) config('nfl.predictions.actual_weather.precip_under_threshold_inches', 0.03))
            || ($temperature !== null && $temperature <= (float) config('nfl.predictions.actual_weather.cold_under_threshold_f', 32))
        );

        return [
            'is_indoor' => $isIndoor,
            'roof_weather_protected' => $isIndoor,
            'weather_total_suppression' => $weatherTotalSuppression,
            'temperature_f' => $temperature,
            'wind_speed_mph' => $wind,
            'wind_gust_mph' => $gust,
            'precipitation_inches' => $precipitation,
            'total_adjustment' => round($metadataAdjustment, 3),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function efficiencyMismatchContext(array $metadata): array
    {
        $trueEpa = $this->number(data_get($metadata, 'true_epa.signal_spread')) ?? 0.0;
        $rolling = $this->number(data_get($metadata, 'rolling_efficiency.signal_spread')) ?? 0.0;
        $opponentAdjusted = $this->number(data_get($metadata, 'opponent_adjusted_efficiency.signal_spread')) ?? 0.0;
        $pressure = $this->number(data_get($metadata, 'line_matchup.signal_spread')) ?? 0.0;
        $combined = $trueEpa + $rolling + $opponentAdjusted;

        return [
            'true_epa_signal' => round($trueEpa, 3),
            'rolling_efficiency_signal' => round($rolling, 3),
            'opponent_adjusted_signal' => round($opponentAdjusted, 3),
            'pressure_signal' => round($pressure, 3),
            'combined_efficiency_signal' => round($combined, 3),
            'epa_edge' => abs($trueEpa) >= 1.0 || abs($combined) >= 2.0,
            'pressure_mismatch' => abs($pressure) >= 1.0,
        ];
    }

    /**
     * @param  list<string>  $reasonCodes
     * @return array<string,mixed>
     */
    private function regressionContext(array $metadata, array $reasonCodes): array
    {
        $turnoverDiffDelta = $this->turnoverDiffDelta($metadata);
        $homeLuck = $this->number(data_get($metadata, 'team_metrics.home.luck_rating'));
        $awayLuck = $this->number(data_get($metadata, 'team_metrics.away.luck_rating'));
        $luckDelta = $homeLuck !== null && $awayLuck !== null ? $homeLuck - $awayLuck : null;

        return [
            'turnover_diff_delta' => $turnoverDiffDelta,
            'turnover_luck_flagged' => ($turnoverDiffDelta !== null && abs($turnoverDiffDelta) >= 1.5) || in_array('turnover_regression_risk', $reasonCodes, true) || in_array('takeaway_edge', $reasonCodes, true),
            'luck_rating_delta' => $luckDelta !== null ? round($luckDelta, 3) : null,
            'one_score_regression_flagged' => in_array('one_score_regression_risk', $reasonCodes, true) || ($luckDelta !== null && abs($luckDelta) >= 1.5),
            'record_overperformance_flagged' => in_array('record_overperformance_fade', $reasonCodes, true),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function scoreComponents(
        ?float $spreadEdge,
        ?float $totalEdge,
        array $crossedKeyNumbers,
        array $nearKeyNumbers,
        bool $teaserCandidate,
        array $marketMovement,
        array $numberDiscipline,
        array $injuryReplacement,
        array $weatherRoof,
        array $efficiencyMismatch,
        array $regressionContext,
        array $metadata,
        array $riskFlags,
        array $riskReasonCodes
    ): array {
        $numberValue = 0;
        if ($spreadEdge !== null) {
            $numberValue += (int) min(18, floor(abs($spreadEdge) * 4));
        }
        if ($totalEdge !== null) {
            $numberValue += (int) min(10, floor(abs($totalEdge) * 2));
        }
        foreach ($crossedKeyNumbers as $keyNumber) {
            $numberValue += in_array($keyNumber, [3, 7], true) ? 6 : 3;
        }
        $numberValue += count($nearKeyNumbers) * 2;
        if ($teaserCandidate) {
            $numberValue += match ($numberDiscipline['teaser_corridor_quality'] ?? 'standard') {
                'premium' => 9,
                'thin' => 3,
                default => 6,
            };
        }
        if ((bool) ($numberDiscipline['low_total_key_number_boost'] ?? false)) {
            $numberValue += 4;
        }

        $marketValue = 0;
        if ((bool) ($marketMovement['steam_freshness'] ?? false)) {
            $marketValue += 6;
        }
        if ((bool) ($marketMovement['market_setter_slow_book_gap'] ?? false)) {
            $marketValue += 4;
        }
        if ((bool) ($marketMovement['positive_clv_profile'] ?? false)) {
            $marketValue += 5;
        }
        if ((bool) ($marketMovement['buyback_resistance'] ?? false)) {
            $marketValue += 2;
        }
        if ($spreadEdge !== null && abs($spreadEdge) >= 4.0) {
            $marketValue += 6;
        }

        $teamQuality = 0;
        if ((bool) ($efficiencyMismatch['epa_edge'] ?? false)) {
            $teamQuality += 7;
        }
        if (abs((float) ($efficiencyMismatch['rolling_efficiency_signal'] ?? 0.0)) >= 1.0) {
            $teamQuality += 4;
        }
        if (abs((float) ($efficiencyMismatch['opponent_adjusted_signal'] ?? 0.0)) >= 1.0) {
            $teamQuality += 4;
        }

        $footballContext = 0;
        if (abs((float) data_get($metadata, 'qb_form.signal_spread', 0.0)) >= 1.0) {
            $footballContext += 5;
        }
        if (abs((float) data_get($metadata, 'line_matchup.signal_spread', 0.0)) >= 1.0) {
            $footballContext += 5;
        }
        if ((bool) data_get($metadata, 'contextual_factors.schedule_spot.applied', false)) {
            $footballContext += 3;
        }
        if ((bool) ($weatherRoof['weather_total_suppression'] ?? false)) {
            $footballContext += 3;
        }
        if ((bool) ($injuryReplacement['qb_replacement_edge'] ?? false)) {
            $footballContext += 5;
        }

        $regression = 0;
        if ((bool) ($regressionContext['turnover_luck_flagged'] ?? false)) {
            $regression += 5;
        }
        if ((bool) ($regressionContext['one_score_regression_flagged'] ?? false)) {
            $regression += 3;
        }
        $riskPenalty = (count($riskFlags) * -4) + (count($riskReasonCodes) * -2);
        if ((bool) ($numberDiscipline['dead_number_tax'] ?? false)) {
            $riskPenalty -= 4;
        }
        if ((bool) ($marketMovement['negative_clv_profile'] ?? false)) {
            $riskPenalty -= 5;
        }
        if ((bool) ($injuryReplacement['injury_overreaction_risk'] ?? false)) {
            $riskPenalty -= 3;
        }

        return [
            'number_value' => min(36, $numberValue),
            'market_value' => min(22, $marketValue),
            'team_quality' => min(16, $teamQuality),
            'football_context' => min(22, $footballContext),
            'regression' => min(8, $regression),
            'risk_penalty' => max(-30, $riskPenalty),
        ];
    }

    private function hasMarketOrNumberValue(?float $spreadEdge, ?float $totalEdge, array $crossedKeyNumbers, array $nearKeyNumbers): bool
    {
        return ($spreadEdge !== null && abs($spreadEdge) >= (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0))
            || ($totalEdge !== null && abs($totalEdge) >= (float) config('nfl.predictions.analysis_layer.min_total_edge', 3.0))
            || $crossedKeyNumbers !== []
            || $nearKeyNumbers !== [];
    }

    private function tier(int $score): string
    {
        return match (true) {
            $score >= 75 => 'official_candidate',
            $score >= 60 => 'lean',
            $score >= 40 => 'watchlist',
            default => 'pass',
        };
    }

    private function turnoverDiffDelta(array $metadata): ?float
    {
        $home = $this->number(data_get($metadata, 'rolling_efficiency.home.turnover_diff'));
        $away = $this->number(data_get($metadata, 'rolling_efficiency.away.turnover_diff'));

        return $home !== null && $away !== null ? round($home - $away, 3) : null;
    }

    private function lineMovement(Game $game): ?float
    {
        $snapshots = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->orderBy('captured_at')
            ->get();

        if ($snapshots->count() < 2) {
            return null;
        }

        $first = $this->homeMarginSpread((array) $snapshots->first()?->odds_data);
        $last = $this->homeMarginSpread((array) $snapshots->last()?->odds_data);

        return $first !== null && $last !== null ? $last - $first : null;
    }

    private function spreadClv(string $pickSide, float $entrySpread, float $closingSpread): float
    {
        return $pickSide === 'home'
            ? $entrySpread - $closingSpread
            : $closingSpread - $entrySpread;
    }

    private function spreadBookRange(Game $game): ?float
    {
        $spreads = [];
        $homeTeamName = $this->oddsData($game)['home_team'] ?? null;
        if (! is_string($homeTeamName) || $homeTeamName === '') {
            return null;
        }

        foreach ((array) ($this->oddsData($game)['bookmakers'] ?? []) as $bookmaker) {
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === $homeTeamName && is_numeric($outcome['point'] ?? null)) {
                        $spreads[] = -1 * (float) $outcome['point'];
                    }
                }
            }
        }

        return count($spreads) >= 2 ? max($spreads) - min($spreads) : null;
    }

    private function isFinal(Game $game): bool
    {
        return in_array((string) $game->status, ['STATUS_FINAL', 'final', 'completed'], true);
    }

    private function venueLooksIndoor(Game $game): bool
    {
        $venue = strtolower((string) $game->venue_name);

        foreach ((array) config('nfl.predictions.contextual_factors.indoor_venue_keywords', []) as $keyword) {
            if ($keyword !== '' && str_contains($venue, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }

    private function spreadPriceForPick(Game $game, string $pickSide): ?int
    {
        $oddsData = $this->oddsData($game);
        $targetTeam = $pickSide === 'home'
            ? (string) ($oddsData['home_team'] ?? $game->homeTeam?->location.' '.$game->homeTeam?->name)
            : (string) ($oddsData['away_team'] ?? $game->awayTeam?->location.' '.$game->awayTeam?->name);

        foreach ((array) ($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === $targetTeam && is_numeric($outcome['price'] ?? null)) {
                        return (int) $outcome['price'];
                    }
                }
            }
        }

        return null;
    }

    private function validTeaserPrice(?int $spreadPrice): bool
    {
        return $spreadPrice !== null && abs($spreadPrice) <= 120;
    }

    private function homeMarginSpread(array $oddsData): ?float
    {
        $homeTeamName = $oddsData['home_team'] ?? null;
        if (! is_string($homeTeamName) || $homeTeamName === '') {
            return null;
        }

        foreach ((array) ($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === $homeTeamName && is_numeric($outcome['point'] ?? null)) {
                        return -1 * (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function oddsData(Game $game): array
    {
        $oddsData = $game->odds_data;
        if (is_string($oddsData)) {
            $oddsData = json_decode($oddsData, true);
        }

        return is_array($oddsData) ? $oddsData : [];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
