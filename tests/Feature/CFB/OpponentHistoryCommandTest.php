<?php

use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Jobs\ESPN\CFB\FetchGameDetails;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Support\Facades\Queue;

test('opponent history command resolves the CFB-specific schedule client', function () {
    $action = app(SyncGamesFromSchedule::class);
    $service = new ReflectionProperty($action, 'espnService');
    expect($service->getValue($action))->toBeInstanceOf(EspnService::class);
    $this->artisan('cfb:sync-opponent-history', ['--season' => 2026])->assertSuccessful();
});

test('opponent history command rejects an unbounded refresh', function () {
    $this->artisan('cfb:sync-opponent-history', ['--days' => 999])->assertFailed();
});

function opponentHistoryTeam(string $subdivision): Team
{
    $team = Team::factory()->create();
    $team->seasonAffiliations()->create(['season' => 2026, 'subdivision' => $subdivision, 'source' => 'cfbd_fbs_membership']);

    return $team;
}

function opponentHistoryGame($home, $away, array $overrides = []): Game
{
    return Game::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'game_date' => '2026-09-26', 'status' => 'STATUS_SCHEDULED', ...$overrides,
    ]);
}

test('history refresh queues past summaries but preserves accepted finals and future games', function () {
    $this->travelTo(now()->setDate(2026, 9, 26)->startOfDay());
    Queue::fake();
    $home = opponentHistoryTeam('FBS');
    $away = opponentHistoryTeam('FCS');
    $future = opponentHistoryGame($home, $away);
    $past = opponentHistoryGame($home, $away, ['game_date' => '2026-09-19']);
    $unverified = opponentHistoryGame($home, $away, ['season' => 2025, 'game_date' => '2025-09-20', 'status' => 'STATUS_FINAL']);
    $accepted = opponentHistoryGame($home, $away, ['game_date' => '2026-09-12', 'status' => 'STATUS_FINAL']);
    GameDataCheck::create(['game_id' => $accepted->id, 'component' => 'boxscore', 'state' => 'complete',
        'validator_version' => '2', 'source_hash' => str_repeat('a', 64), 'discrepancies' => [], 'evidence' => [], 'source_path' => 'test/accepted.json', 'accepted_at' => now()]);
    opponentHistoryGame($home, $away, ['game_date' => '2026-09-05', 'status' => 'STATUS_CANCELED']);
    $this->mock(SyncGamesFromSchedule::class)->shouldReceive('execute')->twice()->andReturn(12);

    $this->artisan('cfb:sync-opponent-history', ['--season' => 2026])->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, 2);
    foreach ([$past, $unverified] as $game) {
        Queue::assertPushed(FetchGameDetails::class,
            fn ($job) => $job->uniqueId() === $game->espn_event_id && $job->queue === 'sync');
    }
    expect($past->fresh()->status)->toBe('STATUS_SCHEDULED')
        ->and($future->fresh()->status)->toBe('STATUS_SCHEDULED');
});

test('FCS opponents of FBS teams take priority over lower team ids', function () {
    $this->travelTo(now()->setDate(2026, 9, 26)->startOfDay());
    Queue::fake();
    $lower = opponentHistoryTeam('FCS');
    $other = opponentHistoryTeam('FCS');
    $fbs = opponentHistoryTeam('FBS');
    $target = opponentHistoryTeam('FCS');
    opponentHistoryGame($lower, $other);
    opponentHistoryGame($fbs, $target);
    $mock = $this->mock(SyncGamesFromSchedule::class);
    $mock->shouldReceive('execute')->once()->with((string) $target->espn_id, 2025)->andReturn(12);
    $mock->shouldReceive('execute')->once()->with((string) $target->espn_id, 2026)->andReturn(12);

    $this->artisan('cfb:sync-opponent-history', ['--season' => 2026, '--limit' => 1])->assertSuccessful();
});

test('history summary work is bounded and prioritizes the current season', function () {
    $this->travelTo(now()->setDate(2026, 9, 26)->startOfDay());
    Queue::fake();
    $home = opponentHistoryTeam('FBS');
    $away = opponentHistoryTeam('FCS');
    opponentHistoryGame($home, $away);
    opponentHistoryGame($home, $away, ['season' => 2025, 'game_date' => '2025-09-20']);
    $current = opponentHistoryGame($home, $away, ['game_date' => '2026-09-19']);
    $this->mock(SyncGamesFromSchedule::class)->shouldReceive('execute')->twice()->andReturn(12);

    $this->artisan('cfb:sync-opponent-history', ['--season' => 2026, '--summary-limit' => 1])->assertSuccessful();

    Queue::assertPushed(FetchGameDetails::class, 1);
    Queue::assertPushed(FetchGameDetails::class,
        fn ($job) => $job->uniqueId() === $current->espn_event_id);
});

test('an empty schedule feed is reported as a failure', function () {
    $this->travelTo(now()->setDate(2026, 9, 26)->startOfDay());
    Queue::fake();
    opponentHistoryGame(opponentHistoryTeam('FBS'), opponentHistoryTeam('FCS'));
    $this->mock(SyncGamesFromSchedule::class)->shouldReceive('execute')->twice()->andReturn(0);
    $this->artisan('cfb:sync-opponent-history', ['--season' => 2026])->assertFailed();
});
