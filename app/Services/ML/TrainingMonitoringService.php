<?php

namespace App\Services\ML;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\ModelTrainingAttempt;
use App\Models\ModelTrainingWorker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrainingMonitoringService
{
    public const STAGES = ['claimed', 'downloading', 'training_and_evaluation', 'uploading', 'completing'];

    public function __construct(private readonly TrainingAttemptService $attempts) {}

    public function heartbeat(ModelTrainingWorker $credential, array $metrics): void
    {
        // Laravel's numeric rules allow numeric strings; persist the typed dashboard contract.
        $normalized = ['version' => (string) $metrics['version']];
        foreach (['cpu_count', 'load_one', 'disk_free_bytes', 'disk_total_bytes', 'memory_total_bytes', 'training_rss_bytes'] as $field) {
            $normalized[$field] = ! isset($metrics[$field]) ? null
                : ($field === 'load_one' ? (float) $metrics[$field] : (int) $metrics[$field]);
        }
        $metrics = $normalized;
        DB::transaction(function () use ($credential, $metrics): void {
            $worker = ModelTrainingWorker::query()->lockForUpdate()->findOrFail($credential->id);
            abort_unless($worker->token_hash === $credential->token_hash && $worker->revoked_at === null && $worker->expires_at->isFuture(), 401);
            abort_unless(in_array('training:report', $worker->abilities, true), 403);
            $existing = DB::table('model_training_worker_health')->where('worker_id', $worker->id)->first();
            DB::table('model_training_worker_health')->updateOrInsert(['worker_id' => $worker->id], [
                'last_seen_at' => now(), 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => $existing?->created_at ?? now(),
            ]);
        });
    }

    /** Snapshots are bounded, ordered and fenced; observing never renews execution leases. */
    public function progress(ModelTrainingAttempt $owner, int $sequence, string $stage, string $tail, array $secrets = []): void
    {
        if (! config('ml.monitoring.enabled', false)) {
            return;
        }
        if ($sequence < 1 || $sequence > 1_000_000 || ! in_array($stage, self::STAGES, true)) {
            throw new RuntimeException('Invalid training progress.');
        }
        $tail = $this->redact($tail, $secrets);
        $this->attempts->observe($owner, function () use ($owner, $sequence, $stage, $tail): void {
            $current = DB::table('model_training_progress')->where('attempt_id', $owner->id)->first();
            if ($current && $current->sequence >= $sequence) {
                return;
            }
            DB::table('model_training_progress')->updateOrInsert(['attempt_id' => $owner->id], [
                'sequence' => $sequence, 'stage' => $stage, 'log_tail' => $tail,
                'reported_at' => now(), 'created_at' => $current?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });
    }

    public function redact(string $text, array $secrets = []): string
    {
        $text = mb_scrub($text, 'UTF-8');
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        $assignments = <<<'REGEX'
~\b(authorization|password|secret|token|api[_-]?key|client_secret|access_token|refresh_token|aws_secret_access_key)\b["']?\s*[:=]\s*(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\r\n,}]+)~i
REGEX;
        $text = preg_replace($assignments, '$1=[redacted]', $text) ?? '';
        $text = preg_replace('~\bBearer[ \t]+[A-Za-z0-9._\~+/=-]+~i', 'Bearer [redacted]', $text) ?? '';
        $text = preg_replace('/\b[a-f0-9]{96}\b/i', '[redacted]', $text) ?? '';
        // Drop control codes; Vue renders the remaining tail as text, never HTML.
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? '';

        return mb_strcut($text, max(0, strlen($text) - 8192), 8192, 'UTF-8');
    }

    public function dashboard(string $sport): array
    {
        $base = ['enabled' => (bool) config('ml.monitoring.enabled', false), 'as_of' => now()->toIso8601String(), 'workers' => [], 'jobs' => []];
        if (! $base['enabled']) {
            return $base;
        }
        $workers = ModelTrainingWorker::query()->whereJsonContains('sports', $sport)->orderBy('name')->limit(50)->get();
        $health = DB::table('model_training_worker_health')->whereIn('worker_id', $workers->modelKeys())->get()->keyBy('worker_id');
        $base['workers'] = $workers->map(function (ModelTrainingWorker $worker) use ($health): array {
            $row = $health->get($worker->id);
            $credential = $worker->revoked_at ? 'revoked' : (! $worker->expires_at->isFuture() ? 'expired' : 'active');
            $seen = $row ? CarbonImmutable::parse($row->last_seen_at) : null;

            return [
                'id' => $worker->id, 'name' => $worker->name, 'credential_status' => $credential,
                'connectivity' => $credential !== 'active' ? 'unavailable' : ($seen === null ? 'never_seen' : ($seen->lt(now()->subSeconds(180)) ? 'unreachable' : 'reachable')),
                'last_seen_at' => $seen?->toIso8601String(), 'metrics' => $row ? json_decode($row->metrics, true) : null,
            ];
        })->all();
        $cycles = ModelRun::query()->where('sport', $sport)->where('run_type', 'weekly_training_cycle')
            ->with(['trainingAttempts' => fn ($query) => $query->orderByDesc('attempt_number')->limit(10)])->latest('started_at')->latest('id')->limit(25)->get();
        $ids = $cycles->flatMap(fn (ModelRun $cycle) => $cycle->trainingAttempts->modelKeys());
        $progress = DB::table('model_training_progress')->whereIn('attempt_id', $ids)->get()->keyBy('attempt_id');
        $uploads = DB::table('model_training_uploads')->whereIn('attempt_id', $ids)->selectRaw('attempt_id, COUNT(*) as files, SUM(bytes) as bytes')->groupBy('attempt_id')->get()->keyBy('attempt_id');
        $artifacts = ModelArtifact::query()->whereIn('id', $cycles->map(fn (ModelRun $cycle) => data_get($cycle->metadata, 'artifact_id'))->filter())->get()->keyBy('id');
        $base['jobs'] = $cycles->map(function (ModelRun $cycle) use ($progress, $uploads, $artifacts): array {
            $attempt = $cycle->trainingAttempts->first();
            $snapshot = $attempt ? $progress->get($attempt->id) : null;
            $artifact = $artifacts->get(data_get($cycle->metadata, 'artifact_id'));
            $status = $cycle->status;
            if ($status === 'running' && $attempt === null && data_get($cycle->metadata, 'stage') === 'remote_ready') {
                $status = 'queued';
            }
            if ($status === 'running' && $attempt?->status === 'running' && ! $attempt->lease_expires_at->isFuture()) {
                $status = 'lease_expired';
            }

            return [
                'id' => $cycle->id, 'worker_id' => data_get($cycle->metadata, 'remote_worker_id'),
                'status' => $status, 'stage' => $cycle->status === 'running' && $snapshot ? $snapshot->stage : data_get($cycle->metadata, 'stage'),
                'started_at' => $cycle->started_at?->toIso8601String(), 'completed_at' => $cycle->completed_at?->toIso8601String(),
                'progress_at' => $snapshot ? CarbonImmutable::parse($snapshot->reported_at)->toIso8601String() : null, 'log_tail' => $snapshot?->log_tail ?? '',
                'files_received' => (int) ($uploads->get($attempt?->id)?->files ?? 0),
                'bytes_received' => (int) ($uploads->get($attempt?->id)?->bytes ?? 0),
                'attempts' => $cycle->trainingAttempts->map(fn (ModelTrainingAttempt $row): array => [
                    'number' => $row->attempt_number, 'status' => $row->status,
                    'started_at' => $row->started_at->toIso8601String(), 'lease_expires_at' => $row->lease_expires_at->toIso8601String(),
                    'completed_at' => $row->completed_at?->toIso8601String(),
                ])->all(),
                'result' => $artifact ? ['artifact_id' => $artifact->id, 'status' => $artifact->status,
                    'model_version' => $artifact->model_version, 'eligible_markets' => (array) data_get($artifact->promotion_decision, 'eligible_markets', []),
                    'evaluation_url' => '/admin/'.$cycle->sport.'-model-monitoring?artifact='.rawurlencode($artifact->id),
                ] : null,
            ];
        })->all();

        return $base;
    }
}
