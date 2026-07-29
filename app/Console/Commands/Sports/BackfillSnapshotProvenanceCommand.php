<?php

namespace App\Console\Commands\Sports;

use App\Models\ModelRun;
use App\Models\NBA\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\SnapshotProvenanceResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BackfillSnapshotProvenanceCommand extends Command
{
    protected $signature = 'sports:backfill-snapshot-provenance
        {--sport= : Restrict to nba, nfl, mlb, wnba, cbb, wcbb, or cfb}
        {--limit=0 : Optional row limit}';

    protected $description = 'Backfill point-in-time status and legacy run lineage for frozen prediction snapshots';

    /**
     * @var array<string, class-string<Model>>
     */
    private array $gameModels = [
        'nba' => Game::class,
        'nfl' => \App\Models\NFL\Game::class,
        'mlb' => \App\Models\MLB\Game::class,
        'wnba' => \App\Models\WNBA\Game::class,
        'cbb' => \App\Models\CBB\Game::class,
        'wcbb' => \App\Models\WCBB\Game::class,
        'cfb' => \App\Models\CFB\Game::class,
    ];

    public function handle(SnapshotProvenanceResolver $resolver, ModelRunRecorder $runRecorder): int
    {
        $sport = $this->option('sport') ? strtolower((string) $this->option('sport')) : null;
        if ($sport !== null && ! isset($this->gameModels[$sport])) {
            $this->error('Unsupported sport.');

            return self::FAILURE;
        }

        $query = PredictionFeatureSnapshot::query()
            ->when($sport, fn ($builder) => $builder->where('sport', $sport))
            ->orderBy('id');

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $snapshots = $query->get();
        $updated = 0;
        $linked = 0;
        $runs = [];

        foreach ($snapshots as $snapshot) {
            $gameModel = $this->gameModels[$snapshot->sport] ?? null;
            $game = $gameModel ? $gameModel::query()->find($snapshot->game_id) : null;
            if (! $game) {
                continue;
            }

            $metadata = is_array($snapshot->model_metadata) ? $snapshot->model_metadata : [];
            $generationMode = (string) data_get($metadata, 'lineage.generation_mode', 'prediction');
            $isHistorical = $generationMode === 'historical_reconstruction';
            $profile = data_get($metadata, 'lineage.historical_profile');
            $payload = [
                'run_type' => $isHistorical ? 'historical_reconstruction' : 'prediction',
                'historical_profile' => $profile,
            ];
            $provenance = $resolver->resolve($game, $payload, $snapshot->generated_at);
            $provenance['lineage_metadata'] = [
                ...$provenance['lineage_metadata'],
                'config_provenance' => $snapshot->model_run_id ? 'recorded' : 'legacy_version_fingerprint',
            ];

            if (! $snapshot->model_run_id) {
                $runKey = implode('|', [
                    $snapshot->sport,
                    $snapshot->model_version,
                    $snapshot->feature_version,
                    $snapshot->blend_version,
                    $provenance['availability_status'],
                ]);

                $run = $runs[$runKey] ??= $this->legacyRun($snapshot, $runRecorder, $runKey);
                $provenance['model_run_id'] = $run->id;
                $linked++;
            }

            $snapshot->forceFill($provenance)->save();
            $updated++;
        }

        $this->info("Updated {$updated} snapshot provenance row(s); linked {$linked} legacy snapshot(s).");

        return self::SUCCESS;
    }

    private function legacyRun(
        PredictionFeatureSnapshot $snapshot,
        ModelRunRecorder $runRecorder,
        string $fingerprint,
    ): ModelRun {
        return $runRecorder->create(
            sport: $snapshot->sport,
            runType: 'legacy_snapshot_import',
            modelVersion: $snapshot->model_version,
            featureVersion: $snapshot->feature_version,
            blendVersion: $snapshot->blend_version,
            metadata: [
                'config_provenance' => 'legacy_version_fingerprint',
                'warning' => 'Original runtime configuration was not recorded.',
                'snapshot_import_id' => (string) Str::uuid(),
            ],
            status: 'completed',
            completedAt: now(),
            configHash: hash('sha256', $fingerprint),
        );
    }
}
