<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\ML\WinProbabilityCalibrationTrainer;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RecordMoneylineCalibrationShadowCommand extends Command
{
    protected $signature = 'cfb:record-moneyline-calibration-shadow
        {--artifact= : Exact artifact UUID; defaults to the latest offline-eligible CFB artifact}
        {--season= : Restrict to a season}
        {--week= : Restrict to a week}
        {--game= : Restrict to a CFB game id}';

    protected $description = 'Record pregame CFB calibration outputs without changing canonical predictions';

    public function handle(
        ModelArtifactRegistry $artifacts,
        WinProbabilityCalibrationTrainer $trainer,
        ModelRunRecorder $runs,
        ShadowModelOutputRecorder $shadowOutputs,
    ): int {
        $artifact = $this->artifact();
        if (! $artifact instanceof ModelArtifact) {
            $this->warn('No offline-eligible CFB moneyline calibration artifact is available.');

            return self::SUCCESS;
        }

        try {
            $model = json_decode(
                (string) File::get($artifacts->materializeArtifact($artifact)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $exception) {
            $this->error('Unable to load the calibration artifact: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_numeric($model['alpha'] ?? null) || ! is_numeric($model['beta'] ?? null)) {
            $this->error('The calibration artifact does not contain valid alpha and beta parameters.');

            return self::FAILURE;
        }

        $minimumWeek = (int) data_get($artifact->trainingRun?->parameters, 'min_week', 0);
        $maximumWeek = (int) data_get($artifact->trainingRun?->parameters, 'max_week', 4);
        $requestedWeek = filled($this->option('week')) ? (int) $this->option('week') : null;
        if ($requestedWeek !== null && ($requestedWeek < $minimumWeek || $requestedWeek > $maximumWeek)) {
            $this->error("Artifact calibration scope is weeks {$minimumWeek}-{$maximumWeek}; week {$requestedWeek} cannot be shadowed.");

            return self::FAILURE;
        }

        $inferenceRun = $runs->forPrediction(
            sport: 'cfb',
            modelVersion: $artifact->model_version,
            featureVersion: $artifact->feature_version,
            blendVersion: $this->blendVersion($artifact),
            metadata: [
                'run_type' => 'shadow_inference',
                'parameters' => [
                    'model_artifact_id' => $artifact->id,
                    'artifact_hash' => $artifact->artifact_hash,
                ],
            ],
        );
        $recorded = 0;
        $skipped = 0;

        foreach ($this->predictions($artifact, $minimumWeek, $maximumWeek)->get() as $prediction) {
            $event = $prediction->sportEvent;
            $game = $event?->cfbGame;
            $input = $prediction->calculationRun?->inputSnapshot;
            $baseline = $prediction->markets
                ->first(fn ($market): bool => $market->market_type === 'moneyline' && $market->selection === 'home');
            $featuresAvailableAt = $input?->latest_source_available_at ?? $input?->captured_at;

            if ($event === null
                || $game === null
                || $input === null
                || $baseline?->probability === null
                || $event->starts_at === null
                || ! now()->lessThan($event->starts_at)
                || $prediction->generated_at?->isAfter($event->starts_at)
                || $input->pregame_safety_status !== 'verified'
                || $input->cutoff_at === null
                || $input->captured_at->isAfter($input->cutoff_at)
                || $input->cutoff_at->isAfter($event->starts_at)
                || $featuresAvailableAt?->isAfter($event->starts_at)) {
                $skipped++;

                continue;
            }

            $baselineProbability = (float) $baseline->probability;
            $calibratedProbability = $trainer->predict($baselineProbability, [
                'alpha' => (float) $model['alpha'],
                'beta' => (float) $model['beta'],
            ]);
            $generatedAt = now();
            $featureHash = hash('sha256', json_encode([
                'canonical_prediction_id' => $prediction->id,
                'canonical_output_hash' => $prediction->output_hash,
                'event_input_snapshot_hash' => $input->content_hash,
                'artifact_hash' => $artifact->artifact_hash,
                'baseline_probability' => round($baselineProbability, 6),
                'calibrated_probability' => round($calibratedProbability, 6),
            ], JSON_THROW_ON_ERROR));
            $snapshot = PredictionFeatureSnapshot::query()->firstOrCreate(
                [
                    'prediction_table' => $prediction->getTable(),
                    'prediction_id' => $prediction->id,
                    'model_version' => $artifact->model_version,
                    'feature_version' => $artifact->feature_version,
                    'blend_version' => $this->blendVersion($artifact),
                ],
                [
                    'sport' => 'cfb',
                    'game_id' => $game->id,
                    'snapshot_run_id' => (string) Str::uuid(),
                    'model_run_id' => $inferenceRun->id,
                    'features' => $input->inputs ?? [],
                    'outputs' => [
                        'baseline_win_probability' => round($baselineProbability, 6),
                        'calibrated_win_probability' => round($calibratedProbability, 6),
                        'win_probability' => round($baselineProbability, 6),
                    ],
                    'market_context' => null,
                    'model_metadata' => [
                        'shadow_inference' => [
                            'artifact_id' => $artifact->id,
                            'artifact_hash' => $artifact->artifact_hash,
                            'training_run_id' => $artifact->training_run_id,
                            'model_run_id' => $inferenceRun->id,
                            'config_hash' => $artifact->trainingRun?->config_hash,
                            'dataset_hash' => $artifact->dataset_hash,
                            'feature_hash' => $featureHash,
                            'baseline_output' => round($baselineProbability, 6),
                            'challenger_output' => round($calibratedProbability, 6),
                            'market_type' => 'win_probability',
                            'profile' => 'cfb-moneyline-platt-v1',
                            'reason' => 'offline_eligible_live_shadow',
                        ],
                    ],
                    'feature_hash' => $featureHash,
                    'generated_at' => $generatedAt,
                    'game_start_at' => $event->starts_at,
                    'features_available_at' => $featuresAvailableAt,
                    'pregame_safe' => true,
                    'availability_status' => 'observed_pregame',
                    'source_timestamps' => $input->source_timestamps,
                    'lineage_metadata' => [
                        'run_type' => 'shadow_inference',
                        'canonical_prediction_id' => $prediction->id,
                        'canonical_prediction_public_id' => $prediction->public_id,
                        'canonical_calculation_run_id' => $prediction->calculation_run_id,
                        'event_input_snapshot_id' => $input->id,
                        'event_input_snapshot_hash' => $input->content_hash,
                        'observed_before_game_start' => true,
                    ],
                ],
            );

            $shadow = $shadowOutputs->record($snapshot);
            $recorded += $snapshot->wasRecentlyCreated && $shadow !== null ? 1 : 0;
        }

        $this->info("Recorded {$recorded} new CFB moneyline shadow observation(s).");
        $this->line("Artifact: {$artifact->id}");
        $this->line("Skipped or already observed: {$skipped}");
        $this->line('Published probabilities changed: 0');

        return self::SUCCESS;
    }

    private function artifact(): ?ModelArtifact
    {
        return ModelArtifact::query()
            ->with('trainingRun')
            ->where('sport', 'cfb')
            ->where('market_type', 'win_probability')
            ->where('model_type', 'cfb_moneyline_platt_calibration')
            ->whereIn('status', ['promotion_eligible', 'promoted'])
            ->when(
                filled($this->option('artifact')),
                fn (Builder $query) => $query->whereKey((string) $this->option('artifact')),
            )
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /** @return Builder<CanonicalPrediction> */
    private function predictions(ModelArtifact $artifact, int $minimumWeek, int $maximumWeek): Builder
    {
        return CanonicalPrediction::query()
            ->with([
                'markets',
                'calculationRun.inputSnapshot',
                'sportEvent.cfbGame',
            ])
            ->where('sport', 'cfb')
            ->where('phase', 'pregame')
            ->where('publication_state', 'published')
            ->whereHas('sportEvent', function (Builder $query) use ($minimumWeek, $maximumWeek): void {
                $query->where('starts_at', '>', now())
                    ->whereBetween('week', [$minimumWeek, $maximumWeek])
                    ->when($this->option('season'), fn (Builder $builder) => $builder->where('season', (int) $this->option('season')))
                    ->when($this->option('week'), fn (Builder $builder) => $builder->where('week', (int) $this->option('week')))
                    ->when($this->option('game'), fn (Builder $builder) => $builder->whereHas(
                        'cfbGame',
                        fn (Builder $gameQuery) => $gameQuery->whereKey((int) $this->option('game')),
                    ));
            })
            ->orderBy('sport_event_id');
    }

    private function blendVersion(ModelArtifact $artifact): string
    {
        return 'shadow-'.$artifact->id;
    }
}
