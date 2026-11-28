<?php

namespace App\Services\ML;

use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Models\ModelTrainingTransferSlot;
use App\Models\ModelTrainingUpload;
use App\Models\ModelTrainingWorker;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbTabularFeatureSchema;
use App\Services\MLB\MlbTabularFiles;
use App\Services\MLB\MlbTabularJsonBudget;
use App\Services\MLB\MlbTabularModelBundle;
use App\Services\MLB\MlbTrainingCompletion;
use App\Services\MLB\MlbTrainingCompletionState;
use App\Services\NFL\NflTabularFeatureSchema;
use App\Services\NFL\NflTabularJsonBudget;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RemoteTrainingService
{
    public function __construct(
        private readonly TrainingAttemptService $attempts,
        private readonly MlArtifactStorage $storage,
        private readonly WeeklyChallengerTrainingService $training,
    ) {}

    /** @return list<array{cycle_id:string,sport:string}> */
    public function tasks(ModelTrainingWorker $worker): array
    {
        return ModelRun::query()->select(['id', 'sport'])->where('run_type', 'weekly_training_cycle')
            ->where('metadata->remote_worker_id', $worker->id)->whereIn('sport', $worker->sports)
            ->whereIn('status', ['running', 'failed'])
            ->whereDoesntHave('trainingAttempts', fn ($query) => $query
                ->where('attempt_number', '>=', max(1, (int) config('ml.training.maximum_attempts', 3)))
                ->whereIn('status', ['failed', 'abandoned']))->orderBy('started_at')->limit(50)
            ->get()->map(fn (ModelRun $cycle): array => ['cycle_id' => $cycle->id, 'sport' => $cycle->sport])->all();
    }

    /** @return array<string, mixed> */
    public function claim(ModelTrainingWorker $worker, string $cycleId): array
    {
        $result = $this->within($worker, $cycleId, null, null, function (ModelRun $cycle) use ($cycleId): array {
            $specification = (array) data_get($cycle->metadata, 'training_specification');
            $latest = $cycle->trainingAttempts()->orderByDesc('attempt_number')->lockForUpdate()->first();
            if ($latest?->status === 'succeeded') {
                return ['status' => 'completed', 'result' => $latest->result];
            }
            // Lost claim replies may be retried without allocating another attempt.
            try {
                $attempt = $latest?->status === 'running' && $latest->lease_expires_at->isFuture()
                ? $latest
                : $this->attempts->claim($cycleId, (int) $specification['timeout_seconds'] + 600);
            } catch (RuntimeException) {
                // Commit expired-attempt/exhaustion receipts before reporting refusal.
                return ['refused' => true];
            }

            return [
                'cycle_id' => $cycleId, 'sport' => $cycle->sport, 'attempt_id' => $attempt->id,
                'owner_token' => $attempt->owner_token, 'run_id' => $attempt->python_run_id,
                'lease_expires_at' => $attempt->lease_expires_at->toIso8601String(),
                'dataset' => $this->publicInput($cycle, 'dataset'),
                'schema' => $this->publicInput($cycle, 'schema'),
                'source_hash' => data_get($cycle->metadata, 'remote_source_hash'),
                'telemetry_sequence' => (int) (config('ml.monitoring.enabled', false) ? DB::table('model_training_progress')->where('attempt_id', $attempt->id)->value('sequence') : 0),
                'options' => [
                    'threads' => (int) $specification['threads'], 'timeout_seconds' => (int) $specification['timeout_seconds'],
                    'tune' => (bool) $specification['tune'], 'explain' => (bool) $specification['explain'],
                ],
            ];
        }, ability: 'claim');
        if (isset($result['refused'])) {
            throw new RuntimeException('Training claim refused.');
        }

        return $result;
    }

    public function input(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner, string $kind): string
    {
        abort_unless(in_array($kind, ['dataset', 'schema'], true), 404);
        $descriptor = $this->within($worker, $cycleId, $attemptId, $owner,
            fn (ModelRun $cycle): array => (array) data_get($cycle->metadata, 'remote_inputs.'.$kind), ability: 'read');

        return $this->materialize($descriptor);
    }

    /** The caller supplies a raw request stream. No client filenames become storage paths. */
    public function upload(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner, string $path, string $expectedHash, mixed $stream, int $expectedBytes): array
    {
        abort_unless(strlen($path) <= 191 && preg_match('~\A(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*|(?:models|calibrators|explanations|tuning)/[A-Za-z0-9_-][A-Za-z0-9_.-]*)\z~', $path) === 1
            && preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) === 1, 422);
        abort_unless($expectedBytes >= 0 && $expectedBytes <= $this->fileLimit($path), 413);
        $mlb = $this->isMlbCycle($cycleId);
        if ($mlb && (DB::connection()->transactionLevel() !== 0 || $worker->getConnection() !== DB::connection()
            || (new ModelRun)->getConnection() !== DB::connection())) {
            throw new RuntimeException('MLB upload staging requires a standalone coherent connection.');
        }
        $slot = $this->reserve($worker, $cycleId, $attemptId, $owner, $path, $expectedHash, $expectedBytes, $mlb);
        $temporary = rtrim((string) config('ml.remote.staging_directory'), '/').'/'.$slot->owner_token;
        File::ensureDirectoryExists($temporary, 0700, true);
        try {
            $local = $temporary.'/upload';
            $output = fopen($local, 'xb');
            if (! is_resource($output) || ! is_resource($stream)) {
                throw new RuntimeException('Unable to read artifact transfer.');
            }
            $bytes = 0;
            $hash = hash_init('sha256');
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1_048_576);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read artifact transfer.');
                    }
                    $bytes += strlen($chunk);
                    abort_if($bytes > $expectedBytes, 413);
                    // A replaced/expired transfer cannot keep consuming staging space.
                    abort_unless(ModelTrainingTransferSlot::query()->whereKey($worker->id)
                        ->where('owner_token', $slot->owner_token)->where('expires_at', '>', now())->exists(), 409);
                    hash_update($hash, $chunk);
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Unable to stage artifact transfer.');
                    }
                }
            } finally {
                fclose($output);
            }
            chmod($local, 0600);
            abort_unless($bytes === $expectedBytes && hash_equals($expectedHash, hash_final($hash)), 422, 'Artifact hash or size mismatch.');

            // A fresh server-owned upload key is unpublished until the current SQL owner fence admits its pointer.
            $staged = $mlb ? $this->stageMlbUpload($local, $attemptId, $expectedHash, $slot->owner_token) : null;

            return $this->within($worker, $cycleId, $attemptId, $owner, function (ModelRun $cycle, ModelTrainingAttempt $attempt) use ($attemptId, $path, $bytes, $local, $expectedHash, $staged, $slot): array {
                abort_unless(($cycle->sport === 'mlb') === ($staged !== null), 409, 'Upload sport binding changed.');
                if ($staged !== null) {
                    $connection = DB::connection();
                    $tokenBytes = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH(owner_token)' : 'length(CAST(owner_token AS BLOB))';
                    $expiryBytes = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH(expires_at)' : 'length(CAST(expires_at AS BLOB))';
                    $currentSlot = ModelTrainingTransferSlot::query()->whereKey($slot->worker_id)->where('attempt_id', $attemptId)
                        ->selectRaw('CASE WHEN '.$tokenBytes.' <= 36 THEN owner_token ELSE NULL END AS owner_token')
                        ->selectRaw('CASE WHEN '.$expiryBytes.' <= 32 THEN expires_at ELSE NULL END AS expires_at')->lockForUpdate()->first();
                    abort_unless($currentSlot !== null && is_string($currentSlot->owner_token) && hash_equals($slot->owner_token, $currentSlot->owner_token)
                        && $currentSlot->getRawOriginal('expires_at') !== null && $currentSlot->expires_at->isFuture(), 409, 'Upload staging ownership expired.');
                }
                $existing = ModelTrainingUpload::query()->where('attempt_id', $attemptId)->where('path', $path)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->sha256, $expectedHash) && $existing->bytes === $bytes, 409, 'Conflicting artifact upload.');

                    return ['path' => $path, 'sha256' => $existing->sha256, 'bytes' => $existing->bytes];
                }
                $uploads = ModelTrainingUpload::query()->where('attempt_id', $attemptId);
                abort_if((clone $uploads)->count() >= (int) config('ml.remote.maximum_files')
                    || (clone $uploads)->sum('bytes') + $bytes > (int) config('ml.remote.maximum_total_bytes'), 413);
                if ($staged !== null) {
                    ['disk' => $disk, 'key' => $key] = $staged;
                } else {
                    $disk = (string) config('ml.storage.disk', 'ml-local');
                    $key = trim((string) config('ml.storage.prefix', 'ml'), '/').'/remote-uploads/'.$attemptId.'/'.$expectedHash;
                    $handle = fopen($local, 'rb');
                    try {
                        if (! Storage::disk($disk)->put($key, $handle, ['visibility' => 'private'])) {
                            throw new RuntimeException('Unable to store artifact transfer.');
                        }
                    } finally {
                        if (is_resource($handle)) {
                            fclose($handle);
                        }
                    }
                }
                abort_unless($attempt->lease_expires_at->isFuture(), 409, 'Training attempt expired during transfer.');
                ModelTrainingUpload::query()->create([
                    'attempt_id' => $attemptId, 'path' => $path, 'disk' => $disk,
                    'object_key' => $key, 'sha256' => $expectedHash, 'bytes' => $bytes,
                ]);

                return ['path' => $path, 'sha256' => $expectedHash, 'bytes' => $bytes];
            }, ability: 'upload', boundedMlb: $mlb);
        } finally {
            File::deleteDirectory($temporary);
            DB::transaction(function () use ($slot): void {
                DB::table('model_training_transfer_budgets')->where('id', 1)->lockForUpdate()->first();
                ModelTrainingTransferSlot::query()->whereKey($slot->worker_id)->where('owner_token', $slot->owner_token)
                    ->update(['reserved_bytes' => 0, 'expires_at' => now()]);
            });
        }
    }

    /** @return array<string, mixed> */
    public function complete(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner, string $expectedHash): array
    {
        $connection = (new ModelRun)->getConnection();
        $length = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH(sport)' : 'length(CAST(sport AS BLOB))';
        $routing = $connection->table('model_runs')->useWritePdo()->where('id', $cycleId)
            ->selectRaw('CASE WHEN '.$length.' <= 16 THEN sport ELSE NULL END AS sport')->first();
        abort_unless($routing !== null && $routing->sport !== null, 404);
        if ($routing->sport === 'mlb') {
            return $this->completeMlb($worker, $cycleId, $attemptId, $owner, $expectedHash);
        }

        return $this->within($worker, $cycleId, $attemptId, $owner, function (ModelRun $cycle, ModelTrainingAttempt $attempt) use ($expectedHash): array {
            $uploads = ModelTrainingUpload::query()->where('attempt_id', $attempt->id)->orderBy('path')->get();
            $digest = hash_init('sha256');
            foreach ($uploads as $upload) {
                hash_update($digest, $upload->path."\0".$upload->sha256."\0".$upload->bytes."\n");
            }
            $outputHash = hash_final($digest);
            abort_unless(hash_equals($expectedHash, $outputHash), 409, 'Conflicting training completion.');
            if ($attempt->status === 'succeeded') {
                return $this->attempts->complete($attempt, $outputHash, fn (): array => []);
            }
            abort_if($uploads->count() > (int) config('ml.remote.maximum_files')
                || $uploads->sum('bytes') > (int) config('ml.remote.maximum_total_bytes'), 413);
            foreach ($uploads as $upload) {
                abort_if($upload->bytes > $this->fileLimit($upload->path), 413);
            }
            $manifestUpload = $uploads->firstWhere('path', 'manifest.json');
            abort_unless($manifestUpload, 422, 'Missing artifact manifest.');
            abort_if($cycle->sport === 'nfl' && $manifestUpload->bytes > 2097152, 413, 'NFL numeric manifest exceeds its fixed byte contract.');
            $manifestPath = $this->storage->materialize($manifestUpload->disk, $manifestUpload->object_key, $manifestUpload->sha256, expectedBytes: $cycle->sport === 'nfl' ? $manifestUpload->bytes : null);
            if ($cycle->sport === 'nfl') {
                $rawManifest = File::get($manifestPath);
                $manifest = NflTabularJsonBudget::decode($rawManifest, 2097152, 50000);
            } else {
                $manifest = json_decode(File::get($manifestPath), true, 64, JSON_THROW_ON_ERROR);
            }
            abort_unless(is_array($manifest) && ! array_is_list($manifest)
                && is_array($manifest['artifacts'] ?? null) && ! array_is_list($manifest['artifacts'])
                && count($manifest['artifacts']) <= (int) config('ml.remote.maximum_files') - 1, 422, 'Invalid artifact inventory.');
            $declared = array_keys($manifest['artifacts']);
            sort($declared);
            abort_unless($declared === $uploads->pluck('path')->reject(fn (string $path): bool => $path === 'manifest.json')->values()->all(), 422, 'Incomplete artifact inventory.');
            foreach ($uploads as $upload) {
                if ($upload->path !== 'manifest.json') {
                    $entry = $manifest['artifacts'][$upload->path] ?? null;
                    abort_unless(is_array($entry) && ($entry['sha256'] ?? null) === $upload->sha256
                        && ($entry['bytes'] ?? null) === $upload->bytes, 422, 'Artifact inventory mismatch.');
                }
            }
            $temporary = $this->temporaryDirectory();
            try {
                foreach ($uploads as $upload) {
                    $source = $this->storage->materialize($upload->disk, $upload->object_key, $upload->sha256, expectedBytes: $cycle->sport === 'nfl' ? $upload->bytes : null);
                    File::ensureDirectoryExists(dirname($temporary.'/'.$upload->path), 0700, true);
                    if (! File::copy($source, $temporary.'/'.$upload->path)) {
                        throw new RuntimeException('Unable to stage verified artifact.');
                    }
                    chmod($temporary.'/'.$upload->path, 0600);
                }
                $specification = (array) data_get($cycle->metadata, 'training_specification');
                foreach ([
                    'model_run_id' => $attempt->python_run_id,
                    'dataset_hash' => data_get($cycle->metadata, 'dataset_hash'),
                    'model_type' => $specification['model_type'],
                    'model_version' => $specification['model_version'],
                    'feature_schema_version' => $specification['feature_version'],
                    'source_hash' => data_get($cycle->metadata, 'remote_source_hash'),
                ] as $field => $expected) {
                    abort_unless(is_string($manifest[$field] ?? null) && hash_equals($expected, $manifest[$field]), 422, 'Artifact lineage mismatch.');
                }
                if ($cycle->sport === 'nfl') {
                    $schema = (array) data_get($cycle->metadata, 'remote_inputs.schema');
                    abort_unless(is_int($schema['bytes'] ?? null) && $schema['bytes'] > 0 && $schema['bytes'] <= 65536, 422, 'NFL requested schema exceeds its byte contract.');
                    $requested = $this->storage->materialize($schema['disk'], $schema['object_key'], $schema['sha256'], expectedBytes: $schema['bytes']);
                    try {
                        app(NflTabularFeatureSchema::class)->assertMatches(
                            $requested, ['bytes' => $schema['bytes'], 'sha256' => $schema['sha256']],
                            $temporary.'/feature_schema.json', (array) ($manifest['artifacts']['feature_schema.json'] ?? []),
                            (string) $specification['feature_version'],
                        );
                    } catch (\Throwable $exception) {
                        abort(422, 'Artifact schema mismatch.');
                    }
                } else {
                    abort_unless(File::isFile($temporary.'/feature_schema.yaml') && hash_equals(
                        (string) data_get($cycle->metadata, 'remote_inputs.schema.sha256'),
                        (string) hash_file('sha256', $temporary.'/feature_schema.yaml'),
                    ), 422, 'Artifact schema mismatch.');
                }
                $dataset = $this->materialize((array) data_get($cycle->metadata, 'remote_inputs.dataset'));
                $cycle->metadata = [...(array) $cycle->metadata, 'dataset_path' => $dataset];

                // Existing registrars verify the entire inventory and report, and never deserialize joblib here.
                return $this->training->completeAttempt($cycle, $attempt, $temporary, $outputHash);
            } finally {
                File::deleteDirectory($temporary);
            }
        }, allowCompleted: true, ability: 'complete');
    }

    /** Inspect/materialize outside SQL, then enter the server-owned source-first coordinator. */
    private function completeMlb(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner, string $expectedHash): array
    {
        $snapshot = $this->mlbCompletionSnapshot($worker, $cycleId, $attemptId, $owner, $expectedHash);
        if ($snapshot['attempt']->status === 'succeeded') {
            return $snapshot['attempt']->result;
        }
        $cycle = $snapshot['cycle'];
        app(MlbArtifactCohortSerializer::class)->assertSupported($cycle->getConnection());
        if (data_get($cycle->metadata, 'mlb_remote_protocol') !== MlbTrainingCompletion::REMOTE_PROTOCOL
            || ! is_array(data_get($cycle->metadata, 'remote_inputs.dataset_proof'))) {
            throw new RuntimeException('MLB remote completion requires its server-owned inspected dataset proof.');
        }
        $uploads = $snapshot['uploads'];
        $paths = array_column($uploads, 'path');
        $required = [...MlbTabularModelBundle::requiredRunFiles(), 'manifest.json'];
        sort($paths, SORT_STRING);
        sort($required, SORT_STRING);
        abort_unless($paths === $required, 422, 'MLB artifact inventory differs from the fixed numeric format.');
        $temporary = $this->temporaryDirectory();
        $runDirectory = $temporary.'/run';
        $inputDirectory = $temporary.'/inputs';
        File::ensureDirectoryExists($runDirectory, 0700, true);
        File::ensureDirectoryExists($inputDirectory, 0700, true);
        try {
            $byPath = array_column($uploads, null, 'path');
            foreach ($uploads as $upload) {
                abort_if($upload['bytes'] > $this->fileLimit($upload['path']), 413);
                $source = $this->storage->materialize($upload['disk'], $upload['object_key'], $upload['sha256'], expectedBytes: $upload['bytes']);
                File::ensureDirectoryExists(dirname($runDirectory.'/'.$upload['path']), 0700, true);
                MlbTabularFiles::copy($source, $runDirectory.'/'.$upload['path'], $upload['bytes'], $upload['sha256']);
            }
            $manifestRaw = file_get_contents($runDirectory.'/manifest.json');
            MlbTabularJsonBudget::inspect($manifestRaw, 2097152, 50000, 24);
            $manifest = json_decode($manifestRaw, true, 24, JSON_THROW_ON_ERROR);
            abort_unless(is_array($manifest) && ($manifest['execution_format'] ?? null) === 'picksports-tabular-numeric-v1'
                && is_array($manifest['artifacts'] ?? null) && count($manifest['artifacts']) === count($required) - 1, 422, 'Invalid MLB numeric manifest.');
            foreach ($uploads as $upload) {
                if ($upload['path'] === 'manifest.json') {
                    continue;
                }
                $entry = $manifest['artifacts'][$upload['path']] ?? null;
                abort_unless(is_array($entry) && count($entry) === 2 && ($entry['sha256'] ?? null) === $upload['sha256']
                    && ($entry['bytes'] ?? null) === $upload['bytes'], 422, 'MLB artifact inventory mismatch.');
            }
            $specification = (array) data_get($cycle->metadata, 'training_specification');
            foreach (['model_run_id' => $snapshot['attempt']->python_run_id,
                'dataset_hash' => data_get($cycle->metadata, 'dataset_hash'), 'model_type' => $specification['model_type'],
                'model_version' => $specification['model_version'], 'feature_schema_version' => $specification['feature_version'],
                'source_hash' => data_get($cycle->metadata, 'remote_source_hash')] as $field => $expected) {
                abort_unless(is_string($manifest[$field] ?? null) && is_string($expected) && hash_equals($expected, $manifest[$field]), 422, 'MLB artifact lineage mismatch.');
            }
            $schema = (array) data_get($cycle->metadata, 'remote_inputs.schema');
            $schemaPath = $this->materializeMlbInput($schema, 65536);
            app(MlbTabularFeatureSchema::class)->assertMatches($schemaPath,
                ['bytes' => $schema['bytes'], 'sha256' => $schema['sha256']], $runDirectory.'/feature_schema.json',
                ['bytes' => $byPath['feature_schema.json']['bytes'], 'sha256' => $byPath['feature_schema.json']['sha256']],
                (string) $specification['feature_version']);
            foreach (['dataset' => ['maximum' => 67108864, 'target' => $inputDirectory.'/dataset.csv'],
                'dataset_proof' => ['maximum' => 2097152, 'target' => $inputDirectory.'/dataset.csv.manifest.json']] as $kind => $policy) {
                $descriptor = (array) data_get($cycle->metadata, 'remote_inputs.'.$kind);
                $source = $this->materializeMlbInput($descriptor, $policy['maximum']);
                MlbTabularFiles::copy($source, $policy['target'], $descriptor['bytes'], $descriptor['sha256']);
            }
            $prepared = $this->training->prepareMlbAttempt($cycle, $snapshot['attempt'], $runDirectory, $expectedHash,
                $worker, $uploads, $inputDirectory.'/dataset.csv');

            return $this->training->commitMlbAttempt($prepared, $worker);
        } catch (RuntimeException|\JsonException $exception) {
            // A concurrent identical completion may have committed while this request staged bytes.
            // Receipt replay revalidates the current credential/latest owner without acquiring sources.
            $current = $this->mlbCompletionSnapshot($worker, $cycleId, $attemptId, $owner, $expectedHash);
            if ($current['attempt']->status === 'succeeded') {
                return $current['attempt']->result;
            }
            throw $exception;
        } finally {
            File::deleteDirectory($temporary);
        }
    }

    private function mlbCompletionSnapshot(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner, string $expectedHash): array
    {
        return $this->within($worker, $cycleId, $attemptId, $owner, function (ModelRun $cycle, ModelTrainingAttempt $attempt) use ($expectedHash): array {
            $uploads = MlbTrainingCompletionState::uploads($cycle->getConnection(), (int) $attempt->id, false);
            $digest = hash_init('sha256');
            foreach ($uploads as $upload) {
                hash_update($digest, $upload['path']."\0".$upload['sha256']."\0".$upload['bytes']."\n");
            }
            abort_unless(hash_equals($expectedHash, hash_final($digest)), 409, 'Conflicting training completion.');

            return compact('cycle', 'attempt', 'uploads');
        }, allowCompleted: true, ability: 'complete', boundedMlb: true);
    }

    private function materializeMlbInput(array $descriptor, int $maximum): string
    {
        if (count($descriptor) !== 4 || ! is_int($descriptor['bytes'] ?? null) || $descriptor['bytes'] < 1 || $descriptor['bytes'] > $maximum) {
            throw new RuntimeException('MLB remote input exceeds its fixed descriptor bound.');
        }
        foreach (['disk' => 255, 'object_key' => 4096, 'sha256' => 64] as $field => $limit) {
            if (! is_string($descriptor[$field] ?? null) || $descriptor[$field] === '' || strlen($descriptor[$field]) > $limit) {
                throw new RuntimeException('MLB remote input scalar is invalid.');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $descriptor['sha256']) !== 1) {
            throw new RuntimeException('MLB remote input digest is invalid.');
        }

        return $this->storage->materialize($descriptor['disk'], $descriptor['object_key'], $descriptor['sha256'], expectedBytes: $descriptor['bytes']);
    }

    public function fail(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner): void
    {
        $this->within($worker, $cycleId, $attemptId, $owner, function (ModelRun $cycle, ModelTrainingAttempt $attempt): void {
            $this->attempts->fail($attempt, new RuntimeException('Remote worker reported a training failure.'));
        }, ability: 'complete');
    }

    public function reportProgress(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner, array $payload, array $secrets): void
    {
        $this->within($worker, $cycleId, $attemptId, $owner, function (ModelRun $cycle, ModelTrainingAttempt $attempt) use ($payload, $secrets): void {
            app(TrainingMonitoringService::class)->progress($attempt, $payload['sequence'], $payload['stage'], (string) ($payload['log_tail'] ?? ''), $secrets);
        }, ability: 'report');
    }

    private function fileLimit(string $path): int
    {
        $binaryLimit = (int) config('ml.remote.maximum_file_bytes');
        if (in_array($path, [
            'preprocessor.json', 'models/logistic_classifier.json', 'models/xgboost_classifier.json',
            'models/xgboost_home_margin.json', 'models/xgboost_total_points.json',
            'calibrators/logistic_regression_platt.json', 'calibrators/logistic_regression_isotonic.json',
            'calibrators/xgboost_platt.json', 'calibrators/xgboost_isotonic.json',
        ], true)) {
            return min($binaryLimit, 8388608);
        }
        if ($path === 'manifest.json' || $path === 'feature_schema.yaml' || $path === 'feature_schema.json') {
            return min($binaryLimit, (int) config('ml.remote.maximum_manifest_bytes'));
        }

        return str_ends_with($path, '.json') ? min($binaryLimit, (int) config('ml.remote.maximum_report_bytes')) : $binaryLimit;
    }

    /** @return array{disk:string,key:string} */
    private function stageMlbUpload(string $local, int $attemptId, string $hash, string $token): array
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('MLB object staging cannot inherit SQL locks.');
        }
        $disk = (string) config('ml.storage.disk', 'ml-local');
        $key = trim((string) config('ml.storage.prefix', 'ml'), '/').'/remote-uploads/'.$attemptId.'/'.$token.'/'.$hash;
        $handle = fopen($local, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to open staged artifact transfer.');
        }
        try {
            if (! Storage::disk($disk)->put($key, $handle, ['visibility' => 'private'])) {
                throw new RuntimeException('Unable to store artifact transfer.');
            }
        } finally {
            fclose($handle);
        }

        // A later credential/lease conflict may leave this exact owned key orphaned; it is never activated by storage alone.
        return compact('disk', 'key');
    }

    private function isMlbCycle(string $cycleId): bool
    {
        $connection = (new ModelRun)->getConnection();
        $length = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH(sport)' : 'length(CAST(sport AS BLOB))';
        $row = $connection->table('model_runs')->useWritePdo()->where('id', $cycleId)
            ->selectRaw('CASE WHEN '.$length.' <= 16 THEN sport ELSE NULL END AS sport')->first();

        return $row?->sport === 'mlb';
    }

    /** Reserve one slot per worker and a global count/byte budget before reading request bytes. */
    private function reserve(ModelTrainingWorker $worker, string $cycleId, int $attemptId, string $owner, string $path, string $hash, int $bytes, bool $boundedMlb = false): ModelTrainingTransferSlot
    {
        $expiredTokens = [];
        $reserved = $this->within($worker, $cycleId, $attemptId, $owner, function (ModelRun $cycle) use ($worker, $attemptId, $path, $hash, $bytes, $boundedMlb, &$expiredTokens): ModelTrainingTransferSlot {
            abort_unless(($cycle->sport === 'mlb') === $boundedMlb, 409, 'Upload sport binding changed.');
            DB::table('model_training_transfer_budgets')->where('id', 1)->lockForUpdate()->firstOrFail();
            $query = ModelTrainingTransferSlot::query()->lockForUpdate();
            if ($boundedMlb) {
                $connection = DB::connection();
                foreach (['worker_id' => 36, 'owner_token' => 36, 'expires_at' => 32] as $field => $maximum) {
                    $length = $connection->getDriverName() === 'mysql' ? 'OCTET_LENGTH('.$field.')' : 'length(CAST('.$field.' AS BLOB))';
                    $query->selectRaw('CASE WHEN '.$length.' <= '.$maximum.' THEN '.$field.' ELSE NULL END AS '.$field);
                }
                $integer = $connection->getDriverName() === 'sqlite' ? "typeof(reserved_bytes) = 'integer' AND " : '';
                $query->selectRaw('CASE WHEN '.$integer.'reserved_bytes BETWEEN 0 AND 536870912 THEN reserved_bytes ELSE NULL END AS reserved_bytes');
                $slots = $query->limit(1001)->get();
                abort_if($slots->count() > 1000 || ($slots->count() === 1000 && ! $slots->contains('worker_id', $worker->id)), 429, 'Upload reservation inventory exceeds its reviewed bound.');
                foreach ($slots as $existingSlot) {
                    if (! is_string($existingSlot->worker_id) || ! Str::isUuid($existingSlot->worker_id)
                        || ! is_string($existingSlot->owner_token) || ! Str::isUuid($existingSlot->owner_token)
                        || $existingSlot->getRawOriginal('expires_at') === null || $existingSlot->reserved_bytes === null) {
                        throw new RuntimeException('MLB transfer reservation exceeds its bounded scalar scope.');
                    }
                }
            } else {
                $slots = $query->get();
            }
            $active = $slots->filter(fn (ModelTrainingTransferSlot $slot): bool => $slot->expires_at->isFuture());
            abort_if($active->contains('worker_id', $worker->id)
                || $active->count() >= (int) config('ml.remote.maximum_concurrent_uploads')
                || $active->sum('reserved_bytes') + $bytes > (int) config('ml.remote.maximum_staging_bytes'), 429, 'Upload staging is busy.');
            $uploads = ModelTrainingUpload::query()->where('attempt_id', $attemptId)->get();
            $existing = $uploads->firstWhere('path', $path);
            if ($existing) {
                abort_unless(hash_equals($existing->sha256, $hash) && $existing->bytes === $bytes, 409, 'Conflicting artifact upload.');
            } else {
                abort_if($uploads->count() >= (int) config('ml.remote.maximum_files')
                    || $uploads->sum('bytes') + $bytes > (int) config('ml.remote.maximum_total_bytes'), 413);
            }
            foreach ($slots->reject(fn (ModelTrainingTransferSlot $slot): bool => $slot->expires_at->isFuture()) as $expired) {
                if ($boundedMlb) {
                    if (! Str::isUuid($expired->owner_token)) {
                        throw new RuntimeException('MLB expired transfer has no owned cleanup identity.');
                    }
                    $expiredTokens[] = $expired->owner_token;
                } else {
                    File::deleteDirectory(rtrim((string) config('ml.remote.staging_directory'), '/').'/'.$expired->owner_token);
                }
            }

            return ModelTrainingTransferSlot::query()->updateOrCreate(['worker_id' => $worker->id], [
                'attempt_id' => $attemptId, 'owner_token' => (string) Str::uuid(), 'reserved_bytes' => $bytes,
                'expires_at' => now()->addSeconds((int) config('ml.remote.upload_lease_seconds')),
            ]);
        }, ability: 'upload', boundedMlb: $boundedMlb);
        foreach ($expiredTokens as $token) {
            File::deleteDirectory(rtrim((string) config('ml.remote.staging_directory'), '/').'/'.$token);
        }

        return $reserved;
    }

    private function within(ModelTrainingWorker $credential, string $cycleId, ?int $attemptId, ?string $owner, Closure $callback, bool $allowCompleted = false, string $ability = 'read', bool $boundedMlb = false): mixed
    {
        $connection = DB::connection();
        if ($boundedMlb && ($credential->getConnection() !== $connection || (new ModelRun)->getConnection() !== $connection)) {
            throw new RuntimeException('MLB remote completion requires one coherent connection.');
        }

        return $connection->transaction(function () use ($credential, $cycleId, $attemptId, $owner, $callback, $allowCompleted, $ability, $boundedMlb, $connection): mixed {
            $worker = $boundedMlb ? MlbTrainingCompletionState::worker($connection, $credential->id)
                : ModelTrainingWorker::query()->lockForUpdate()->findOrFail($credential->id);
            abort_unless(hash_equals($worker->token_hash, $credential->token_hash)
                && $worker->revoked_at === null && $worker->expires_at->isFuture(), 401);
            abort_unless(in_array('training:'.$ability, $worker->abilities, true), 403);
            $cycle = $boundedMlb ? MlbTrainingCompletionState::cycle($connection, $cycleId)
                : ModelRun::query()->lockForUpdate()->findOrFail($cycleId);
            abort_unless($cycle->run_type === 'weekly_training_cycle'
                && data_get($cycle->metadata, 'remote_worker_id') === $worker->id
                && in_array($cycle->sport, $worker->sports, true), 404);
            $attempt = null;
            if ($attemptId !== null) {
                $attempt = $boundedMlb ? MlbTrainingCompletionState::latestAttempt($connection, $cycleId)
                    : $cycle->trainingAttempts()->orderByDesc('attempt_number')->lockForUpdate()->first();
                abort_unless($attempt?->id === $attemptId && is_string($owner) && hash_equals($attempt->owner_token, $owner), 409, 'Stale training attempt.');
                abort_unless(($allowCompleted && $attempt->status === 'succeeded')
                    || ($attempt->status === 'running' && $attempt->lease_expires_at->isFuture()), 409, 'Training attempt is no longer active.');
            }

            return $callback($cycle, $attempt);
        });
    }

    private function materialize(array $descriptor): string
    {
        return $this->storage->materialize($descriptor['disk'], $descriptor['object_key'], $descriptor['sha256']);
    }

    /** @return array{sha256:string,bytes:int} */
    private function publicInput(ModelRun $cycle, string $kind): array
    {
        return [
            'sha256' => (string) data_get($cycle->metadata, 'remote_inputs.'.$kind.'.sha256'),
            'bytes' => (int) data_get($cycle->metadata, 'remote_inputs.'.$kind.'.bytes'),
        ];
    }

    private function temporaryDirectory(): string
    {
        $path = rtrim((string) config('ml.remote.staging_directory'), '/').'/'.Str::uuid();
        File::ensureDirectoryExists($path, 0700, true);

        return $path;
    }
}
