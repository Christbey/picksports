<?php

namespace App\Console\Commands\Sports;

use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\SnapshotMetadataStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CompactSnapshotMetadataCommand extends Command
{
    protected $signature = 'sports:compact-snapshot-metadata
        {--sport=mlb : Snapshot sport}
        {--through-id= : Required inclusive upper ID boundary}
        {--after-id=0 : Exclusive lower ID boundary}
        {--limit=100 : Maximum observation rows inspected (1–1000)}
        {--restore : Restore shared metadata inline instead of compacting}
        {--apply : Persist changes; otherwise preview only}';

    protected $description = 'Compact or restore a bounded batch of snapshot metadata without removing observations';

    public function handle(SnapshotMetadataStorage $storage): int
    {
        $through = filter_var($this->option('through-id'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $sport = (string) $this->option('sport');
        if ($through === false || $through < 1 || $after === false || $after < 0 || $after > $through
            || $limit === false || $limit < 1 || $limit > 1000
            || ! in_array($sport, ['nfl', 'nba', 'mlb', 'wnba', 'cfb', 'cbb', 'wcbb'], true)) {
            $this->error('Provide a supported sport, --through-id, ordered nonnegative --after-id, and --limit=1..1000.');

            return self::INVALID;
        }

        $summary = ['mode' => $this->option('apply') ? 'apply' : 'preview', 'restore' => (bool) $this->option('restore'),
            'sport' => $sport, 'inspected' => 0, 'eligible' => 0, 'changed' => 0, 'last_successful_id' => $after,
            'json_bytes_selected' => 0];
        try {
            $ids = PredictionFeatureSnapshot::query()->where('sport', $sport)
                ->where('id', '>', $after)->where('id', '<=', $through)->orderBy('id')->limit($limit)->pluck('id');
            foreach ($ids as $id) {
                $result = DB::transaction(function () use ($id, $sport, $storage): array {
                    $snapshot = PredictionFeatureSnapshot::query()->where('sport', $sport)->when($this->option('apply'), fn ($query) => $query->lockForUpdate())->findOrFail($id);
                    $raw = $snapshot->getRawOriginal('model_metadata');
                    $shared = $storage->isReference($raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
                    $metadata = $snapshot->model_metadata;
                    $json = $metadata === null ? null : ($shared
                        ? $storage->originalJson(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), $snapshot->metadataPayload) : $raw);
                    $eligible = $this->option('restore') ? $shared : (! $shared && $json !== null
                        && strlen($json) >= (int) config('snapshot-storage.minimum_json_bytes'));
                    if (! $eligible || ! $this->option('apply')) {
                        return ['eligible' => $eligible, 'changed' => false, 'bytes' => $eligible ? strlen($json) : 0];
                    }

                    $before = $snapshot->getRawOriginal();
                    unset($before['model_metadata']);
                    $replacement = $this->option('restore') ? $json
                        : json_encode($storage->storeJson($json), JSON_THROW_ON_ERROR);
                    PredictionFeatureSnapshot::query()->whereKey($id)->toBase()->update(['model_metadata' => $replacement]);
                    $fresh = $snapshot->fresh();
                    $other = $fresh->getRawOriginal();
                    unset($other['model_metadata']);
                    $freshRaw = $fresh->getRawOriginal('model_metadata');
                    $freshJson = $this->option('restore') ? $freshRaw
                        : $storage->originalJson(json_decode($freshRaw, true, 512, JSON_THROW_ON_ERROR), $fresh->metadataPayload);
                    if ($before !== $other || ! is_array($fresh->model_metadata)
                        || $storage->jsonSignature($freshJson) !== $storage->jsonSignature($json)) {
                        throw new RuntimeException('Snapshot metadata verification failed; transaction rolled back.');
                    }

                    return ['eligible' => true, 'changed' => true, 'bytes' => strlen($json)];
                }, 3);
                $summary['inspected']++;
                $summary['eligible'] += (int) $result['eligible'];
                $summary['changed'] += (int) $result['changed'];
                $summary['json_bytes_selected'] += $result['bytes'];
                $summary['last_successful_id'] = (int) $id;
            }
        } catch (Throwable $error) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
