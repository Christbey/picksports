<?php

namespace App\Services\MLB;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Services\MLB\Predictions\MlbCalculationReleaseDefinition;
use App\Services\MLB\Predictions\MlbCalculator;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Collection;

/** Read-only counterfactuals; never registers releases or persists forecasts. */
class MlbTuningReplaySource
{
    /** @return array{rows:Collection,excluded:array} */
    public function rows(int $season, ?string $schema, int $limit): array
    {
        $rows = collect();
        $excluded = [];
        foreach (app(MlbAnalysisForecastSource::class)->rows($season, null, null, $schema, $limit) as $entry) {
            $forecast = $entry['forecast'];
            $run = $forecast->source->calculationRun;
            $release = $run->release;
            $input = $run->inputSnapshot;
            $reason = null;
            if ($release->calculator_name !== MlbCalculationReleaseDefinition::CALCULATOR_NAME
                || $release->input_schema_version !== MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION
                || $release->semantic_version !== MlbCalculationReleaseDefinition::SEMANTIC_VERSION) {
                $reason = 'unsupported_calculator_release';
            } elseif (! hash_equals($release->configuration_hash, app(CanonicalPayloadHasher::class)->hash($release->configuration ?? []))) {
                $reason = 'configuration_hash_mismatch';
            }
            $row = (object) ['snapshot' => new EventInputSnapshotData(
                schemaVersion: $input->schema_version, inputs: $input->inputs, capturedAt: $input->captured_at,
                cutoffAt: $input->cutoff_at, latestSourceAvailableAt: $input->latest_source_available_at,
                sourceTimestamps: $input->source_timestamps ?? [], pregameSafetyStatus: $input->pregame_safety_status),
                'release' => CalculationReleaseData::fromModel($release),
                'actual_spread' => (float) $forecast->game->home_score - (float) $forecast->game->away_score,
                'actual_total' => (float) $forecast->game->home_score + (float) $forecast->game->away_score,
                'lineage' => [...$entry['lineage'], 'configuration_hash' => $release->configuration_hash, 'code_revision' => $release->code_revision]];
            if ($reason === null) {
                try {
                    $baseline = $this->project($row);
                    if (abs($baseline['spread'] - $forecast->predictedSpread) > 0.000001
                        || abs($baseline['total'] - $forecast->predictedTotal) > 0.000001
                        || abs($baseline['probability'] - $forecast->winProbability) > 0.000001) {
                        $reason = 'baseline_replay_mismatch';
                    }
                } catch (PredictionLifecycleException) {
                    $reason = 'incomplete_replay_inputs';
                }
            }
            if ($reason !== null) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
            } else {
                $row->baseline = $baseline;
                $rows->push($row);
            }
        }

        return ['rows' => $rows, 'excluded' => $excluded];
    }

    /** @return array{spread:float,total:float,probability:float} */
    public function project(object $row, ?float $hfa = null, ?float $averageTotal = null, ?float $coefficient = null): array
    {
        if ($hfa === null && $averageTotal === null && $coefficient === null && isset($row->baseline)) {
            return $row->baseline;
        }
        $configuration = $row->release->configuration;
        foreach (['elo.home_field_advantage' => $hfa, 'total.average_total' => $averageTotal, 'spread.probability_coefficient' => $coefficient] as $key => $value) {
            if ($value !== null) {
                data_set($configuration, $key, $value);
            }
        }
        $release = new CalculationReleaseData(...[...(array) $row->release, 'configuration' => $configuration,
            'configurationHash' => app(CanonicalPayloadHasher::class)->hash($configuration)]);
        $output = app(MlbCalculator::class)->calculate($row->snapshot, $release);
        $markets = collect($output->markets);
        $result = ['spread' => -$markets->first(fn ($m) => $m->marketType === 'spread' && $m->selection === 'home')->projectedLine,
            'total' => $markets->first(fn ($m) => $m->marketType === 'total' && $m->selection === 'combined')->projectedLine,
            'probability' => $markets->first(fn ($m) => $m->marketType === 'moneyline' && $m->selection === 'home')->probability];
        foreach ($result as $value) {
            if (! is_finite($value)) {
                throw new PredictionLifecycleException('Nonfinite MLB tuning replay.');
            }
        }

        return $result;
    }
}
