<?php

namespace App\Console\Commands\NFL;

use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\PredictionFeatureSnapshotArchive;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class PilotFeatureSnapshotArchiveCommand extends Command
{
    protected $signature = 'nfl:pilot-feature-snapshot-archive
        {snapshot* : IDs of up to 25 legacy NFL snapshots}';

    protected $description = 'Archive and verify a bounded NFL snapshot sample while keeping its inline data';

    public function handle(PredictionFeatureSnapshotArchive $archive): int
    {
        $ids = array_unique($this->argument('snapshot'));
        if ($ids === [] || count($ids) > 25 || array_filter($ids, fn (string $id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('Provide up to 25 positive snapshot IDs.');

            return self::INVALID;
        }

        if (app()->isProduction() && config('filesystems.disks.'.config('provider-data.storage.disk').'.driver') !== 's3') {
            $this->error('The production pilot requires the configured private S3-compatible disk.');

            return self::FAILURE;
        }

        $query = PredictionFeatureSnapshot::query()->without('metadataPayload')->whereKey($ids)
            ->where('sport', 'nfl')->whereIn('blend_version', ['nfl-multi-signal-v1', 'nfl-multi-signal-v1-full-historical'])
            ->whereNotNull('model_metadata');

        if ((clone $query)->count() !== count($ids)) {
            $this->error('Every snapshot must be an existing legacy NFL snapshot with metadata.');

            return self::INVALID;
        }

        $timings = [];
        $archiveBytes = 0;
        try {
            foreach ($query->select(['id', 'features', 'model_metadata'])->lazyById(1) as $snapshot) {
                $rawFeatures = $snapshot->getRawOriginal('features');
                $rawMetadata = $snapshot->getRawOriginal('model_metadata');
                $manifest = $archive->archive($rawFeatures, $rawMetadata);
                $projected = new PredictionFeatureSnapshot;
                $projected->setRawAttributes($archive->projections($manifest, $rawMetadata));
                $started = hrtime(true);
                $features = $projected->features;
                $metadata = $projected->model_metadata;
                $timings[] = (hrtime(true) - $started) / 1e6;

                if ($features !== $snapshot->features || $metadata !== $snapshot->model_metadata) {
                    throw new RuntimeException('Archive replay differs for snapshot '.$snapshot->id.'.');
                }
                $archiveBytes += $manifest['compressed_bytes'];
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Feature snapshot archive pilot failed.');

            return self::FAILURE;
        }

        sort($timings);
        $count = count($timings);
        $this->line(json_encode([
            'verified_snapshots' => $count,
            'archive_bytes' => $archiveBytes,
            'read_p50_ms' => round($timings[(int) ceil($count * 0.5) - 1], 1),
            'read_p95_ms' => round($timings[(int) ceil($count * 0.95) - 1], 1),
            'read_max_ms' => round($timings[$count - 1], 1),
            'peak_php_bytes' => memory_get_peak_usage(true),
            'php_limit' => ini_get('memory_limit'),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
