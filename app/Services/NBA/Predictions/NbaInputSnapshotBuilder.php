<?php

namespace App\Services\NBA\Predictions;

use App\Actions\NBA\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\TeamMetric;
use App\Models\SportEvent;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

class NbaInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $snapshot = parent::build($event, $release);
        $source = data_get($release->configuration, 'forecast.source');
        if ($source === null) {
            return $snapshot;
        }
        if ($source !== NbaFullModelReleaseDefinition::FORECAST_SOURCE
            || $release->inputSchemaVersion !== NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION) {
            throw new PredictionLifecycleException('NBA full-model release inputs are incompatible.');
        }
        $frozen = data_get($release->configuration, 'forecast.configuration');
        if (! is_array($frozen) || array_keys($frozen) !== ['nba.elo', 'nba.prediction', 'nba.season', 'nba.possession_coefficient']) {
            throw new PredictionLifecycleException('NBA full-model configuration is unavailable.');
        }
        $original = collect(array_keys($frozen))->mapWithKeys(fn (string $key): array => [$key => config($key)])->all();
        try {
            config($frozen);
            $game = $event->nbaGame()->with(['homeTeam', 'awayTeam'])->firstOrFail();
            $preview = app(GeneratePrediction::class)->preview($game);
        } finally {
            config($original);
        }
        if ($preview === null) {
            throw new PredictionLifecycleException('NBA established model inputs are unavailable.');
        }
        $capturedAt = now()->toImmutable();
        if ($snapshot->cutoffAt === null || $capturedAt->gte($snapshot->cutoffAt)) {
            throw new PredictionLifecycleException('NBA established model capture must precede kickoff.');
        }

        if ($snapshot->latestSourceAvailableAt?->gt($capturedAt)) {
            throw new PredictionLifecycleException('NBA full-model sources cannot be available after capture.');
        }

        return new EventInputSnapshotData(
            schemaVersion: NbaFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            inputs: [...$snapshot->inputs, 'established_model' => [
                'version' => NbaFullModelReleaseDefinition::FORECAST_SOURCE,
                'model_version' => $preview['model_version'] ?? null,
                'feature_version' => $preview['feature_version'] ?? null,
                'blend_version' => $preview['blend_version'] ?? null,
                'outputs' => array_intersect_key($preview, array_flip(['predicted_spread', 'predicted_total', 'win_probability', 'confidence_score'])),
                'evidence' => (array) ($preview['_snapshot'] ?? []),
                'model_metadata' => (array) ($preview['model_metadata'] ?? []),
            ]],
            capturedAt: $capturedAt, cutoffAt: $snapshot->cutoffAt,
            latestSourceAvailableAt: $capturedAt,
            sourceTimestamps: [...$snapshot->sourceTimestamps, 'established_model_capture' => $capturedAt->toIso8601String()],
            pregameSafetyStatus: $snapshot->pregameSafetyStatus,
            metadata: [...$snapshot->metadata, 'established_model_configuration' => 'frozen_release'],
        );
    }

    protected function sport(): string
    {
        return 'nba';
    }

    protected function inputSchemaVersion(): string
    {
        return NbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'nbaGame';
    }

    /** @return class-string<Model> */
    protected function gameModel(): string
    {
        return Game::class;
    }

    /** @return class-string<Model> */
    protected function teamMetricModel(): string
    {
        return TeamMetric::class;
    }

    /** @return class-string<Model> */
    protected function playerInjuryModel(): string
    {
        return PlayerInjury::class;
    }
}
