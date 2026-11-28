<?php

use App\Models\Healthcheck;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

it('refuses unreviewed stored commands without invoking Artisan and keeps the recommendation visible', function (string $action) {
    $admin = User::factory()->admin()->create();
    Healthcheck::create(['sport' => 'nba', 'check_type' => 'validation_pipeline_order', 'status' => 'failing', 'message' => 'Synthetic failure', 'metadata' => ['recommended_action' => $action], 'checked_at' => now()]);
    Artisan::shouldReceive('call')->andReturn(0);
    $this->actingAs($admin)->from('/admin/healthchecks')->post('/admin/healthchecks/sync', ['sport' => 'nba', 'check_type' => 'validation_pipeline_order'])->assertRedirect()->assertSessionHas('error');
    Artisan::shouldNotHaveReceived('call');
    $this->withoutVite()->get('/admin/healthchecks?view=validation')->assertInertia(fn (Assert $page) => $page
        ->where('checks_by_sport.nba.0.metadata.recommended_action', $action)
        ->where('checks_by_sport.nba.0.remediation.supported', false));
})->with(['migrate:fresh --force', 'nfl:generate-predictions', 'sports:ai-daily-predictions --sport=nba --force', 'nba:sync-odds --api-key=secret', 'nba:sync-odds; migrate:fresh']);

it('executes a reviewed recommendation with typed arguments rather than the stored command string', function () {
    Healthcheck::create(['sport' => 'nfl', 'check_type' => 'validation_nfl_research_coverage', 'status' => 'warning', 'message' => 'Synthetic missing research', 'metadata' => ['recommended_action' => 'nfl:research-pipeline --days-forward=3 --limit=4'], 'checked_at' => now()]);
    Artisan::shouldReceive('call')->once()->with('nfl:research-pipeline', ['--days-forward' => 3, '--limit' => 4])->andReturn(0);
    $this->actingAs(User::factory()->admin()->create())->from('/admin/healthchecks')->post('/admin/healthchecks/sync', ['sport' => 'nfl', 'check_type' => 'validation_nfl_research_coverage'])->assertRedirect()->assertSessionHas('success');
});
