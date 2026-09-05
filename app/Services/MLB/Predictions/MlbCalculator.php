<?php

namespace App\Services\MLB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Contracts\Predictions\SportCalculator;
use App\Exceptions\Predictions\PredictionLifecycleException;

class MlbCalculator implements SportCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        if ($release->sport !== 'mlb' || $release->phase !== 'pregame' || $snapshot->schemaVersion !== MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION) {
            throw new PredictionLifecycleException('MLB calculator received incompatible lifecycle data.');
        }
        $config = $release->configuration;
        $home = (array) data_get($snapshot->inputs, 'home', []);
        $away = (array) data_get($snapshot->inputs, 'away', []);
        $hm = (array) data_get($home, 'metrics', []);
        $am = (array) data_get($away, 'metrics', []);
        $teamWeight = $this->numeric($config, 'elo.team_weight');
        $pitcherWeight = $this->numeric($config, 'elo.pitcher_weight');
        $weightTotal = $teamWeight + $pitcherWeight;
        if ($weightTotal <= 0) {
            throw new PredictionLifecycleException('MLB Elo weights must have a positive sum.');
        }
        $teamWeight /= $weightTotal;
        $pitcherWeight /= $weightTotal;
        $homeCombined = ($this->numeric($home, 'elo') * $teamWeight) + ($this->numeric($snapshot->inputs, 'pitching.home.elo') * $pitcherWeight);
        $awayCombined = ($this->numeric($away, 'elo') * $teamWeight) + ($this->numeric($snapshot->inputs, 'pitching.away.elo') * $pitcherWeight);
        $hfa = (bool) data_get($snapshot->inputs, 'event.neutral_site', false) ? 0.0 : $this->numeric($config, 'elo.home_field_advantage');
        $eloMargin = (($homeCombined + $hfa) - $awayCombined) / $this->positive($config, 'elo.points_per_run');

        $defaultRuns = $this->positive($config, 'total.default_team_runs');
        $homeRuns = $this->numericOr($hm, 'runs_per_game', $defaultRuns);
        $homeAllowed = $this->numericOr($hm, 'runs_allowed_per_game', $defaultRuns);
        $awayRuns = $this->numericOr($am, 'runs_per_game', $defaultRuns);
        $awayAllowed = $this->numericOr($am, 'runs_allowed_per_game', $defaultRuns);
        $homeExpected = ($homeRuns + $awayAllowed) / 2;
        $awayExpected = ($awayRuns + $homeAllowed) / 2;
        $metricMargin = $homeExpected - $awayExpected;
        $sample = min((int) data_get($hm, 'wins', 0) + (int) data_get($hm, 'losses', 0), (int) data_get($am, 'wins', 0) + (int) data_get($am, 'losses', 0));
        $reliability = min(1.0, $sample / max(1, (int) $this->numeric($config, 'spread.minimum_metric_games')));
        $metricWeight = min(0.8, max(0, $this->numeric($config, 'spread.metric_weight') * $reliability));
        $recent = ($this->numericOr($hm, 'recent_form_rating', 0) - $this->numericOr($am, 'recent_form_rating', 0)) * $this->numeric($config, 'context.recent_spread_weight');
        $fatigue = ($this->numericOr($am, 'rest_travel_fatigue', 0) - $this->numericOr($hm, 'rest_travel_fatigue', 0)) * $this->numeric($config, 'context.fatigue_spread_weight');
        $injury = (($this->numericOr($hm, 'injury_adjusted_team_rating', $this->numeric($home, 'elo')) - $this->numericOr($am, 'injury_adjusted_team_rating', $this->numeric($away, 'elo'))) - ($this->numeric($home, 'elo') - $this->numeric($away, 'elo'))) * $this->numeric($config, 'context.injury_rating_spread_weight');
        $margin = (($eloMargin * (1 - $metricWeight)) + ($metricMargin * $metricWeight) + $recent + $fatigue + $injury)
            * (1 - min(0.75, max(0, $this->numeric($config, 'spread.output_regression_weight'))));
        $homeMargin = round($margin, 1);

        $weather = (array) data_get($snapshot->inputs, 'weather', []);
        $weatherAdjustment = 0.0;
        if ($weather !== [] && ! (bool) data_get($weather, 'is_indoor', false)) {
            $weatherAdjustment += ($this->numericOr($weather, 'temperature_f', $this->numeric($config, 'context.weather_temperature_baseline')) - $this->numeric($config, 'context.weather_temperature_baseline')) * $this->numeric($config, 'context.temperature_total_per_degree');
            $weatherAdjustment += $this->numericOr($weather, 'wind_speed_mph', 0) * $this->numeric($config, 'context.wind_total_per_mph');
        }
        $venue = (string) data_get($snapshot->inputs, 'venue.name', '');
        $parkAdjustment = is_numeric(data_get($config, "parks.{$venue}")) ? (float) data_get($config, "parks.{$venue}") : 0.0;
        $rawTotal = $homeExpected + $awayExpected + $parkAdjustment + $weatherAdjustment;
        $totalRegression = min(0.75, max(0, $this->numeric($config, 'total.output_regression_weight')));
        $projectedTotal = round(($rawTotal * (1 - $totalRegression)) + ($this->positive($config, 'total.average_total') * $totalRegression), 1);
        $homeProbability = round(1 / (1 + exp(-$homeMargin / $this->positive($config, 'spread.probability_coefficient'))), 6);
        $awayProbability = round(1 - $homeProbability, 6);
        $confidence = round(max($homeProbability, $awayProbability) * 100, 2);

        return new PredictionOutput(
            markets: [
                new PredictionMarketOutput('moneyline', 'home', probability: $homeProbability, confidenceScore: $confidence),
                new PredictionMarketOutput('moneyline', 'away', probability: $awayProbability, confidenceScore: $confidence),
                new PredictionMarketOutput('spread', 'home', projectedLine: -$homeMargin, confidenceScore: $confidence),
                new PredictionMarketOutput('total', 'combined', projectedLine: $projectedTotal, confidenceScore: $confidence),
            ],
            metadata: ['home_margin' => $homeMargin, 'reason_codes' => [$eloMargin >= 0 ? 'HOME_COMBINED_ELO_EDGE' : 'AWAY_COMBINED_ELO_EDGE'], 'market_conventions' => ['spread' => 'sportsbook_home_line', 'total' => 'combined_runs', 'moneyline_probability' => 'decimal_zero_to_one']],
            diagnostics: ['home_combined_elo' => round($homeCombined, 3), 'away_combined_elo' => round($awayCombined, 3), 'elo_home_margin' => round($eloMargin, 4), 'metric_home_margin' => round($metricMargin, 4), 'metric_reliability' => round($reliability, 4), 'park_total_adjustment' => $parkAdjustment, 'weather_total_adjustment' => round($weatherAdjustment, 4), 'raw_total' => round($rawTotal, 4), 'projected_total' => $projectedTotal],
            generatedAt: $snapshot->capturedAt,
        );
    }

    /** @param array<string,mixed> $values */
    private function numeric(array $values, string $key): float
    {
        $value = data_get($values, $key);
        if (! is_numeric($value)) {
            throw new PredictionLifecycleException("MLB calculation input {$key} must be numeric.");
        }

        return (float) $value;
    }

    /** @param array<string,mixed> $values */
    private function numericOr(array $values, string $key, float $fallback): float
    {
        $value = data_get($values, $key);

        return is_numeric($value) ? (float) $value : $fallback;
    }

    /** @param array<string,mixed> $values */
    private function positive(array $values, string $key): float
    {
        $value = $this->numeric($values, $key);
        if ($value <= 0) {
            throw new PredictionLifecycleException("MLB calculation input {$key} must be positive.");
        }

        return $value;
    }
}
