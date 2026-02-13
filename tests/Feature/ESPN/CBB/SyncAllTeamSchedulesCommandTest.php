<?php

use App\Jobs\ESPN\CBB\FetchTeamSchedule;
use App\Models\CBB\Team;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

it('dispatches FetchTeamSchedule jobs for all teams', function () {
    Queue::fake();

    Team::factory()->count(5)->create();

    artisan('espn:sync-cbb-all-team-schedules');

    Queue::assertPushed(FetchTeamSchedule::class, 5);
});

it('dispatches correct number of jobs when multiple teams exist', function () {
    Queue::fake();

    Team::factory()->count(10)->create();

    artisan('espn:sync-cbb-all-team-schedules');

    Queue::assertPushed(FetchTeamSchedule::class, 10);
});

it('handles case when no teams exist', function () {
    Queue::fake();

    artisan('espn:sync-cbb-all-team-schedules');

    Queue::assertNothingPushed();
});

it('prevents past games from being reset to STATUS_SCHEDULED', function () {
    $team = Team::factory()->create(['espn_id' => '123']);

    // Mock ESPN API response with a past game that's marked as scheduled
    $espnService = Mockery::mock(\App\Services\ESPN\CBB\EspnService::class);
    $espnService->shouldReceive('getSchedule')
        ->with('123')
        ->andReturn([
            'events' => [
                [
                    'id' => 'event-1',
                    'uid' => 's:40~l:41~e:event-1',
                    'date' => now()->subDays(7)->toIso8601String(), // Past game
                    'name' => 'Team A at Team B',
                    'shortName' => 'A @ B',
                    'season' => ['year' => 2026, 'type' => 2],
                    'week' => ['number' => 1],
                    'status' => ['type' => ['name' => 'STATUS_SCHEDULED']], // ESPN incorrectly returns SCHEDULED
                    'competitions' => [
                        [
                            'competitors' => [
                                ['team' => ['id' => '123'], 'homeAway' => 'home', 'score' => 0],
                                ['team' => ['id' => '456'], 'homeAway' => 'away', 'score' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

    $this->app->instance(\App\Services\ESPN\CBB\EspnService::class, $espnService);

    Team::factory()->create(['espn_id' => '456']);

    $action = new \App\Actions\ESPN\CBB\SyncTeamSchedule($espnService);
    $action->execute('123');

    // Verify the game was created with STATUS_FINAL (not STATUS_SCHEDULED)
    expect(\App\Models\CBB\Game::query()->where('espn_event_id', 'event-1')->first())
        ->status->toBe('STATUS_FINAL');
});
