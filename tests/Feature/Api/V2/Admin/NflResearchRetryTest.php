<?php

use App\Jobs\NFL\RetryGameResearch;
use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Models\User;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    config(['nfl_research.enabled' => true, 'ai.features.nfl_game_context_research.enabled' => true]);
    $this->game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => now()->addDays(2)->toDateString(), 'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED']);
    $this->url = '/api/v2/admin/nfl/games/'.$this->game->id.'/research-retry';
});

it('requires admin access for both retry and status', function () {
    $this->postJson($this->url)->assertUnauthorized();
    $this->getJson($this->url)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($this->url)->assertForbidden();
    $this->getJson($this->url)->assertForbidden();
    Queue::assertNothingPushed();
});

it('queues one audited retry and returns the existing run on duplicate clicks', function () {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);
    $first = $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.requested_by', $admin->id);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.run_id', $first->json('data.run_id'));
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'queued');
    Queue::assertPushed(RetryGameResearch::class, 1);
});

it('rejects disabled research and games that have started', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    config(['nfl_research.enabled' => false]);
    $this->postJson($this->url)->assertUnprocessable();
    config(['nfl_research.enabled' => true]);
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->postJson($this->url)->assertUnprocessable();
    $this->game->update(['status' => 'STATUS_SCHEDULED', 'game_date' => now()->subDay()->toDateString()]);
    $this->postJson($this->url)->assertUnprocessable();
    Queue::assertNothingPushed();
});

it('refreshes sources forces assessment and preserves blocked outcomes', function (?string $reason, string $status) {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);
    $this->postJson($this->url)->assertAccepted();
    $job = Queue::pushed(RetryGameResearch::class)->first();
    $ingestor = Mockery::mock(OfficialSourceIngestor::class);
    $ingestor->shouldReceive('sync')->once()->with(Mockery::type('array'))->andReturn([]);
    $pipeline = Mockery::mock(ResearchPipeline::class);
    $pipeline->shouldReceive('review')->once()->withArgs(fn ($game, $research, $force, $actor) => $game->id === $this->game->id && $research && $force && $actor === $admin->id)
        ->andReturn(new ResearchRevision(['brief' => ['research_refresh' => ['deferred_reason' => $reason]]]));
    $job->handle($ingestor, $pipeline);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', $status);
    $this->postJson($this->url)->assertAccepted();
    Queue::assertPushed(RetryGameResearch::class, 2);
})->with([[null, 'completed'], ['research_daily_budget_reached', 'blocked']]);

it('reports failed workers and releases the lock for another attempt', function () {
    Sanctum::actingAs(User::factory()->admin()->create());
    $this->postJson($this->url)->assertAccepted();
    $job = Queue::pushed(RetryGameResearch::class)->first();
    $job->failed(new RuntimeException('worker timeout'));
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'failed');
    $this->postJson($this->url)->assertAccepted();
    Queue::assertPushed(RetryGameResearch::class, 2);
});

it('does not run queued research after kickoff or after a run has expired', function (bool $expired) {
    Sanctum::actingAs(User::factory()->admin()->create());
    $this->postJson($this->url)->assertAccepted();
    $job = Queue::pushed(RetryGameResearch::class)->first();
    if ($expired) {
        Cache::forget(RetryGameResearch::key($this->game->id));
    } else {
        $this->game->update(['status' => 'STATUS_IN_PROGRESS']);
    }
    $ingestor = Mockery::mock(OfficialSourceIngestor::class);
    $ingestor->shouldNotReceive('sync');
    $pipeline = Mockery::mock(ResearchPipeline::class);
    $pipeline->shouldNotReceive('review');
    $job->handle($ingestor, $pipeline);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', $expired ? 'idle' : 'failed');
})->with([false, true]);
