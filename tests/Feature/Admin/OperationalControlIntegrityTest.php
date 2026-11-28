<?php

use App\Models\Healthcheck;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\ValidationRun;
use App\Services\CommandHeartbeatService;
use App\Services\Sports\SportsPipelineRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

it('never records a nonzero repair exit as a successful heartbeat', function () {
    $admin = User::factory()->admin()->create();
    $this->mock(SportsPipelineRegistry::class)->shouldReceive('healthcheckCommand')->with('nba', 'heartbeat_sync')->andReturn(['command' => 'synthetic:repair', 'arguments' => []]);
    Artisan::shouldReceive('call')->once()->with('synthetic:repair', [])->andReturn(7);
    $heartbeat = $this->mock(CommandHeartbeatService::class);
    $heartbeat->shouldNotReceive('recordSuccess');
    $heartbeat->shouldReceive('recordFailure')->once();
    $this->actingAs($admin)->from('/admin/healthchecks')->post('/admin/healthchecks/sync', ['sport' => 'nba', 'check_type' => 'heartbeat_sync'])
        ->assertRedirect('/admin/healthchecks')->assertSessionHas('error');
});

it('does not expose command exception diagnostics in health-control responses', function (string $action) {
    $admin = User::factory()->admin()->create();
    if ($action === 'sync') {
        $this->mock(SportsPipelineRegistry::class)->shouldReceive('healthcheckCommand')->andReturn(['command' => 'synthetic:repair', 'arguments' => []]);
        $heartbeat = $this->mock(CommandHeartbeatService::class);
        $heartbeat->shouldNotReceive('recordSuccess');
        $heartbeat->shouldReceive('recordFailure')->once()->withArgs(fn ($command, $sport, $source, $error) => ! str_contains($error, 'private-secret'));
    }
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('Provider failed https://provider.invalid?apiKey=private-secret'));
    $this->actingAs($admin)->from('/admin/healthchecks')->post('/admin/healthchecks/'.$action, ['sport' => 'nba', 'check_type' => 'heartbeat_sync', 'mode' => 'heartbeat'])
        ->assertRedirect()->assertSessionHas('error');
    expect(json_encode(session('error')))->not->toContain('private-secret')->not->toContain('provider.invalid');
})->with(['sync', 'run']);

it('does not expose mapping sync provider diagnostics in the browser', function () {
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('Provider apiKey=private-secret'));
    $this->actingAs(User::factory()->admin()->create())->from('/settings/team-mappings')->post('/settings/team-mappings/sync', ['provider' => 'odds'])->assertRedirect();
    expect(json_encode(session('error')))->not->toContain('private-secret');
});

it('stores one canonical notification channel list from ordinary form controls', function (string $channel) {
    $user = User::factory()->create();
    $body = ['enabled' => true, 'notification_types' => ['email' => 'email', 0 => 'email', 'push' => 'push', 1 => 'push'], 'minimum_edge' => 5, 'time_window_start' => '09:00', 'time_window_end' => '23:00', 'digest_mode' => 'realtime', 'daily_digest_subscribed' => true];
    if ($channel === 'settings') {
        $this->actingAs($user)->patch('/settings/alert-preferences', $body)->assertRedirect()->assertSessionHasNoErrors();
    } elseif ($channel === 'store') {
        $this->actingAs($user)->postJson('/api/v2/alert-preferences', $body)->assertCreated()->assertJsonPath('data.notification_types', ['email', 'push']);
    } else {
        UserAlertPreference::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->putJson('/api/v2/alert-preferences', $body)->assertOk()->assertJsonPath('data.notification_types', ['email', 'push']);
    }
    expect(UserAlertPreference::where('user_id', $user->id)->sole()->notification_types)->toBe(['email', 'push']);
})->with(['settings', 'store', 'update']);

it('presents existing keyed notification channels as a list without rewriting history', function () {
    $user = User::factory()->create();
    $pref = UserAlertPreference::factory()->create(['user_id' => $user->id, 'notification_types' => ['email' => 'email', 0 => 'email', 'push' => 'push']]);
    $before = $pref->fresh()->getRawOriginal();
    $this->withoutVite()->actingAs($user)->get('/settings/alert-preferences')->assertInertia(fn (Assert $page) => $page->where('preference.notification_types', ['email', 'push']));
    $this->getJson('/api/v2/alert-preferences')->assertOk()->assertJsonPath('data.notification_types', ['email', 'push']);
    expect($pref->fresh()->getRawOriginal())->toBe($before);
});

it('serializes known health observation instants with explicit offsets', function () {
    config(['app.timezone' => 'America/Chicago']);
    $observed = CarbonImmutable::parse('2026-09-14T18:00:00Z')->setTimezone('America/Chicago');
    Healthcheck::create(['sport' => 'nba', 'check_type' => 'heartbeat_sync', 'status' => 'passing', 'message' => 'Synthetic observed check', 'metadata' => [], 'checked_at' => $observed]);
    $this->withoutVite()->actingAs(User::factory()->admin()->create())->get('/admin/healthchecks')->assertInertia(fn (Assert $page) => $page->where('checks_by_sport.nba.0.checked_at', $observed->toIso8601String()));
});

it('serializes known validation completion instants without viewer-local inference', function () {
    config(['app.timezone' => 'America/Chicago']);
    $completed = CarbonImmutable::parse('2026-09-14T18:00:00Z')->setTimezone('America/Chicago');
    ValidationRun::create(['command_name' => 'healthcheck:validate-data', 'scope' => 'sport:nba', 'status' => 'passing', 'summary' => ['execution_status' => 'completed', 'checks_execution_status' => 'completed'], 'started_at' => $completed->subMinute(), 'completed_at' => $completed]);
    $this->withoutVite()->actingAs(User::factory()->admin()->create())->get('/admin/healthchecks?view=validation&sport=nba')->assertInertia(fn (Assert $page) => $page->where('latest_validation_run.completed_at', $completed->toIso8601String())->where('selected_validation_run.completed_at', $completed->toIso8601String()));
});
