<?php

use App\Models\ModelTrainingWorker;
use App\Models\User;
use App\Services\ML\TrainingAttemptService;
use App\Services\ML\TrainingMonitoringService;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('ml.monitoring.enabled', true);
    config()->set('ml.remote.enabled', true);
    $this->token = bin2hex(random_bytes(48));
    $this->worker = ModelTrainingWorker::query()->create([
        'id' => (string) Str::uuid(), 'name' => 'Home Mini', 'token_hash' => hash('sha256', $this->token),
        'sports' => ['nfl'], 'abilities' => ['training:report'], 'expires_at' => now()->addDay(),
    ]);
    $this->cycle = app(ModelRunRecorder::class)->create(
        sport: 'nfl', runType: 'weekly_training_cycle', modelVersion: 'test', featureVersion: 'test', blendVersion: 'test',
        metadata: ['remote_worker_id' => $this->worker->id, 'stage' => 'training_prepared'],
    );
    $this->attempt = app(TrainingAttemptService::class)->claim($this->cycle->id, 600);
    $this->headers = ['Authorization' => 'Bearer '.$this->token, 'X-Training-Owner' => $this->attempt->owner_token];
    $this->progressUrl = '/api/ml-worker/v1/cycles/'.$this->cycle->id.'/attempts/'.$this->attempt->id.'/progress';
});

it('records scoped heartbeats and bounded redacted monotonic progress without renewing leases', function () {
    $lease = $this->attempt->lease_expires_at->toIso8601String();
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => ['version' => 'test-worker', 'disk_free_bytes' => 123]], $this->headers)->assertOk();
    $createdAt = DB::table('model_training_worker_health')->value('created_at');
    $this->travel(1)->seconds();
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => ['version' => 'test-worker']], $this->headers)->assertOk();
    expect(DB::table('model_training_worker_health')->value('created_at'))->toBe($createdAt);
    $this->postJson($this->progressUrl, ['sequence' => 2, 'stage' => 'uploading', 'log_tail' => 'Safe line '.$this->token.' '.$this->attempt->owner_token."\npassword=hidden"], $this->headers)->assertOk();
    $this->postJson($this->progressUrl, ['sequence' => 1, 'stage' => 'downloading', 'log_tail' => 'obsolete'], $this->headers)->assertOk();
    $row = DB::table('model_training_progress')->sole();
    expect($row->stage)->toBe('uploading')->and($row->sequence)->toBe(2)
        ->and($row->log_tail)->toContain('Safe line', '[redacted]')->not->toContain($this->token, $this->attempt->owner_token, 'hidden', 'obsolete')
        ->and($this->attempt->refresh()->lease_expires_at->toIso8601String())->toBe($lease);
});

it('rejects wrong scopes assignments owners expired leases and revoked workers', function () {
    $body = ['sequence' => 1, 'stage' => 'training_and_evaluation', 'log_tail' => 'output'];
    $this->postJson($this->progressUrl, $body)->assertUnauthorized();
    $this->worker->update(['abilities' => ['training:read']]);
    $this->postJson($this->progressUrl, $body, $this->headers)->assertForbidden();
    $this->worker->update(['abilities' => ['training:report'], 'sports' => ['mlb']]);
    $this->postJson($this->progressUrl, $body, $this->headers)->assertNotFound();
    $this->worker->update(['sports' => ['nfl']]);
    $this->postJson($this->progressUrl, $body, [...$this->headers, 'X-Training-Owner' => (string) Str::uuid()])->assertConflict();
    $this->travel(601)->seconds();
    $this->postJson($this->progressUrl, $body, $this->headers)->assertConflict();
    $this->worker->update(['revoked_at' => now()]);
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => ['version' => 'test']], $this->headers)->assertUnauthorized();
    expect(DB::table('model_training_progress')->count())->toBe(0);
});

it('keeps health distinct from job state and restricts operational data to admins', function () {
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => ['version' => 'test']], $this->headers)->assertOk();
    $this->getJson('/admin/training-operations/nfl')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/admin/training-operations/nfl')->assertForbidden();
    $admin = User::factory()->admin()->create();
    $response = $this->actingAs($admin)->getJson('/admin/training-operations/nfl')->assertOk()
        ->assertJsonPath('workers.0.connectivity', 'reachable')->assertJsonPath('jobs.0.status', 'running');
    expect($response->getContent())->not->toContain($this->token, $this->attempt->owner_token, 'token_hash', 'dataset_path', 'object_key');
    $this->travel(181)->seconds();
    $this->getJson('/admin/training-operations/nfl')->assertJsonPath('workers.0.connectivity', 'unreachable')->assertJsonPath('jobs.0.status', 'running');
    $this->travel(420)->seconds();
    $this->getJson('/admin/training-operations/nfl')->assertJsonPath('jobs.0.status', 'lease_expired');
    expect($this->cycle->refresh()->status)->toBe('running');
    $this->getJson('/admin/training-operations/mlb')->assertJsonCount(0, 'workers')->assertJsonCount(0, 'jobs');
});

it('bounds metadata accepts no arbitrary metric fields and keeps monitoring opt in', function () {
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => ['version' => 'test', 'secret' => 'no']], $this->headers)->assertUnprocessable();
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => ['version' => 'test', 'load_one' => -1]], $this->headers)->assertUnprocessable();
    $this->postJson($this->progressUrl, ['sequence' => 1, 'stage' => 'promoted', 'log_tail' => 'no'], $this->headers)->assertUnprocessable();
    $this->postJson($this->progressUrl, ['sequence' => 1, 'stage' => 'uploading', 'log_tail' => str_repeat('a', 8193)], $this->headers)->assertUnprocessable();
    config()->set('ml.monitoring.enabled', false);
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => ['version' => 'test']], $this->headers)->assertNotFound();
    expect(app(TrainingMonitoringService::class)->dashboard('nfl')['jobs'])->toBe([]);
});

it('refuses late progress from a recovered attempt and preserves terminal job state', function () {
    $this->travel(601)->seconds();
    $replacement = app(TrainingAttemptService::class)->claim($this->cycle->id, 600);
    $this->postJson($this->progressUrl, ['sequence' => 100, 'stage' => 'completing', 'log_tail' => 'late'], $this->headers)->assertConflict();
    app(TrainingAttemptService::class)->complete($replacement, str_repeat('a', 64), fn () => ['status' => 'completed']);
    expect(app(TrainingMonitoringService::class)->dashboard('nfl')['jobs'][0]['status'])->toBe('completed')
        ->and(DB::table('model_training_progress')->count())->toBe(0);
});

it('normalizes accepted numeric strings to dashboard numbers and preserves unavailable metrics', function () {
    $this->postJson('/api/ml-worker/v1/heartbeat', ['metrics' => [
        'version' => 'test', 'load_one' => '1.5', 'cpu_count' => '8', 'disk_free_bytes' => '1024', 'training_rss_bytes' => null,
    ]], $this->headers)->assertOk();
    $worker = app(TrainingMonitoringService::class)->dashboard('nfl')['workers'][0];
    expect($worker['metrics']['load_one'])->toBe(1.5)
        ->and($worker['metrics']['cpu_count'])->toBe(8)
        ->and($worker['metrics']['disk_free_bytes'])->toBe(1024)
        ->and($worker['metrics']['training_rss_bytes'])->toBeNull()
        ->and($worker['metrics']['memory_total_bytes'])->toBeNull();
});

it('redacts quoted credential assignments and other bearer values in persisted logs', function () {
    $tail = 'Safe output {"password":"json-password","nested":{"api_key":"json-api-key"}}'
        ."\nAuthorization: Bearer different.jwt-token\nBearer another-token\nrefresh_token='quoted-refresh'";
    $this->postJson($this->progressUrl, ['sequence' => 1, 'stage' => 'uploading', 'log_tail' => $tail], $this->headers)->assertOk();
    $stored = DB::table('model_training_progress')->sole()->log_tail;
    expect($stored)->toContain('Safe output', '[redacted]')
        ->not->toContain('json-password', 'json-api-key', 'different.jwt-token', 'another-token', 'quoted-refresh');
});
