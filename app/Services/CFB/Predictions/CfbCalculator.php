<?php

namespace App\Services\CFB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Services\Predictions\Football\CanonicalFootballCalculator;

class CfbCalculator extends CanonicalFootballCalculator
{
    public function calculate(EventInputSnapshotData $snapshot, CalculationReleaseData $release): PredictionOutput
    {
        if (! data_get($release->configuration, 'inputs.sample_aware_context', false)) {
            return parent::calculate($snapshot, $release);
        }

        $inputs = $snapshot->inputs;
        $minimumGames = max(1, (int) data_get($release->configuration, 'spread.minimum_metric_games', 6));
        $reliability = [];
        $totals = [];
        foreach (['home', 'away'] as $side) {
            $metrics = (array) data_get($inputs, $side.'.metrics', []);
            $games = (int) ($metrics['wins'] ?? 0) + (int) ($metrics['losses'] ?? 0);
            $currentSeason = (int) ($metrics['record_season'] ?? 0) === (int) data_get($inputs, 'event.season');
            $weight = $currentSeason ? min(1, max(0, $games) / $minimumGames) : 0;
            $reliability[$side] = $weight;
            foreach (['recent_form_rating', 'turnover_differential'] as $field) {
                $metrics[$field] = is_numeric($metrics[$field] ?? null) ? $metrics[$field] * $weight : null;
            }
            $prior = (array) data_get($inputs, $side.'.prior_metrics', []);
            foreach (['points_per_game', 'points_allowed_per_game'] as $field) {
                $default = (float) data_get($release->configuration, 'total.default_team_points', 28);
                $priorGames = (int) ($prior['wins'] ?? 0) + (int) ($prior['losses'] ?? 0);
                $baseline = $priorGames > 0 && is_numeric($prior[$field] ?? null) ? (float) $prior[$field] : $default;
                // Previous-season fallback is a prior, not current-season evidence.
                if (! $currentSeason && $games > 0 && is_numeric($metrics[$field] ?? null)) {
                    $baseline = (float) $metrics[$field];
                }
                $totals[$side][$field] = $games > 0 && is_numeric($metrics[$field] ?? null)
                    ? $weight * $metrics[$field] + (1 - $weight) * $baseline : $baseline;
            }
            $inputs[$side]['metrics'] = $metrics;
        }

        // Compare the same rating family on both sides. FPI is an external prior;
        // locally derived power ratings require current-season sample shrinkage.
        $rating = null;
        foreach (['fpi', 'predictive_rating', 'power_rating'] as $field) {
            if (is_numeric(data_get($inputs, 'home.metrics.'.$field)) && is_numeric(data_get($inputs, 'away.metrics.'.$field))) {
                $rating = $field;
                break;
            }
        }
        foreach (['home', 'away'] as $side) {
            $value = $rating === null ? null : data_get($inputs, $side.'.metrics.'.$rating);
            foreach (['fpi', 'predictive_rating', 'power_rating'] as $field) {
                $inputs[$side]['metrics'][$field] = null;
            }
            $inputs[$side]['metrics']['power_rating'] = $value === null ? null
                : $value * ($rating === 'fpi' ? 1 : $reliability[$side]);
        }

        $adjusted = new EventInputSnapshotData(
            schemaVersion: $snapshot->schemaVersion, inputs: $inputs,
            capturedAt: $snapshot->capturedAt, cutoffAt: $snapshot->cutoffAt,
        );
        $output = parent::calculate($adjusted, $release);
        $fpiBaseline = null;
        if ($rating === 'fpi' && data_get($release->configuration, 'spread.rating_baseline') === 'fpi_points') {
            // FPI is already points above average on a neutral field. Use it as
            // a baseline, not a small additive bonus on top of correlated Elo.
            $homeFieldPoints = data_get($inputs, 'event.neutral_site', false) ? 0.0
                : $release->configuration['elo']['home_field_advantage'] / $release->configuration['elo']['points_per_spread_point'];
            $fpiBaseline = $inputs['home']['metrics']['power_rating'] - $inputs['away']['metrics']['power_rating'] + $homeFieldPoints;
            $context = array_sum(array_intersect_key($output->diagnostics, array_flip([
                'recent_adjustment', 'turnover_adjustment', 'fatigue_adjustment',
                'injury_rating_adjustment', 'availability_adjustment',
            ])));
            $regression = max(0, min(0.75, (float) data_get($release->configuration, 'spread.output_regression_weight', 0.1)));
            $homeMargin = round(($fpiBaseline + $context) * (1 - $regression), 1);
            $probability = round(1 / (1 + exp(-$homeMargin / $release->configuration['spread']['probability_coefficient'])), 6);
            $confidence = round(max($probability, 1 - $probability) * 100, 2);
            $output = new PredictionOutput(
                markets: array_map(fn ($m) => match ($m->marketType) {
                    'spread' => new PredictionMarketOutput('spread', 'home', projectedLine: -$homeMargin, confidenceScore: $confidence),
                    'moneyline' => new PredictionMarketOutput('moneyline', $m->selection,
                        probability: $m->selection === 'home' ? $probability : round(1 - $probability, 6), confidenceScore: $confidence),
                    default => $m,
                }, $output->markets),
                metadata: [...$output->metadata, 'home_margin' => $homeMargin,
                    'reason_codes' => [$fpiBaseline >= 0 ? 'HOME_FPI_EDGE' : 'AWAY_FPI_EDGE']],
                diagnostics: [...$output->diagnostics, 'power_adjustment' => 0.0],
                generatedAt: $output->generatedAt,
            );
        }
        $rawTotal = ($totals['home']['points_per_game'] + $totals['away']['points_allowed_per_game']
            + $totals['away']['points_per_game'] + $totals['home']['points_allowed_per_game']) / 2;
        $regression = max(0, min(0.75, (float) data_get($release->configuration, 'total.output_regression_weight', 0.2)));
        $configuration = $release->configuration;
        $recent = (float) data_get($inputs, 'home.metrics.recent_form_rating', 0)
            + (float) data_get($inputs, 'away.metrics.recent_form_rating', 0);
        $fatigue = (float) data_get($inputs, 'home.metrics.rest_travel_fatigue', 0)
            + (float) data_get($inputs, 'away.metrics.rest_travel_fatigue', 0);
        $injuryPenalty = ($output->diagnostics['home_injuries']['out'] + $output->diagnostics['away_injuries']['out'])
            * data_get($configuration, 'injuries.out_total_penalty')
            + ($output->diagnostics['home_injuries']['questionable'] + $output->diagnostics['away_injuries']['questionable'])
            * data_get($configuration, 'injuries.questionable_total_penalty');
        // Blend before rounding; adjusting the parent's rounded total loses tenths.
        $projectedTotal = round(($rawTotal + $recent * data_get($configuration, 'context.recent_total_weight')
            - $fatigue * data_get($configuration, 'context.fatigue_total_weight') - $injuryPenalty) * (1 - $regression)
            + data_get($configuration, 'total.average_total') * $regression, 1);
        $markets = array_map(fn ($market) => $market->marketType === 'total'
            ? new PredictionMarketOutput(
                $market->marketType, $market->selection, projectedLine: $projectedTotal,
                confidenceScore: $market->confidenceScore,
            ) : $market, $output->markets);
        $quality = CfbPredictionInputQuality::assess($snapshot->inputs);

        return new PredictionOutput(
            markets: $markets,
            metadata: [...$output->metadata, 'input_quality' => $quality,
                'reason_codes' => [...$output->metadata['reason_codes'], ...$quality['risk_flags']]],
            diagnostics: [...$output->diagnostics, 'raw_total' => round($rawTotal, 4),
                'projected_total' => collect($markets)->first(fn ($m) => $m->marketType === 'total')->projectedLine,
                'spread_baseline' => $fpiBaseline === null ? 'elo_scoring' : 'fpi_points',
                'fpi_home_margin' => $fpiBaseline === null ? null : round($fpiBaseline, 4),
                'context_reliability' => $reliability, 'paired_rating_family' => $rating, 'input_quality' => $quality],
            generatedAt: $output->generatedAt,
        );
    }

    protected function expectedSport(): string
    {
        return 'cfb';
    }

    protected function expectedInputSchemaVersion(): string
    {
        return CfbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }
}
