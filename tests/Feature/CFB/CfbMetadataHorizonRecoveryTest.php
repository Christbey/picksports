<?php

use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('verifies an explicitly selected unknown carrier before applying the future kickoff horizon', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-11-27 12:00:00');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create(['division' => 'FBS'])->id,
        'away_team_id' => Team::factory()->create(['division' => 'FBS'])->id, 'espn_event_id' => 'recovery-synthetic',
        'season' => 2026, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-01', 'game_time' => '00:00:00']);
    $calls = 0;
    $payload = ['header' => ['id' => 'recovery-synthetic', 'season' => ['year' => 2026],
        'competitions' => [['date' => '2026-11-29T20:00:00Z', 'dateValid' => true, 'timeValid' => true,
            'status' => ['type' => ['name' => 'STATUS_SCHEDULED']]]]]];
    $this->mock(EspnService::class)->shouldReceive('getGame')->zeroOrMoreTimes()->andReturnUsing(function () use (&$calls, $payload) {
        $calls++;

        return $payload;
    });
    $this->artisan('espn:sync-cfb-pregame-metadata', ['--season' => 2026, '--game-id' => $game->id])->assertSuccessful();
    expect($calls)->toBe(1)->and($game->fresh()->game_date->toDateString())->toBe('2026-11-29');
    Http::assertNothingSent();
    $this->travelBack();
});

it('attempts metadata verification before rejecting an explicitly scoped stale unknown pipeline carrier', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-11-27 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create(['division' => 'FBS'])->id,
        'away_team_id' => Team::factory()->create(['division' => 'FBS'])->id, 'espn_event_id' => 'pipeline-recovery-synthetic',
        'season' => 2026, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-01', 'game_time' => '00:00:00']);
    $calls = 0;
    $this->mock(SyncGameMetadata::class)->shouldReceive('execute')->zeroOrMoreTimes()
        ->andReturnUsing(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('Synthetic unavailable source: hold, do not generate.');
        });
    $exit = Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026, '--game' => $game->id]);
    expect($calls)->toBe(1)->and($exit)->toBe(2);
    Http::assertNothingSent();
    $this->travelBack();
});

it('keeps dry-run recovery free of provider IO even for old unknown carriers', function () {
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    $this->travelTo('2026-11-27 12:00:00');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-10-01', 'game_time' => '00:00:00']);
    $this->mock(SyncGameMetadata::class)->shouldNotReceive('execute');
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--game' => $game->id, '--dry-run' => true])->assertSuccessful();
    Http::assertNothingSent();
    $this->travelBack();
});

it('reports a verified but now-past kickoff as excluded instead of a metadata failure', function () {
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    $this->travelTo('2026-11-27 12:00:00');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'espn_event_id' => 'past-recovery-synthetic',
        'season' => 2026, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-01', 'game_time' => '00:00:00']);
    $this->mock(EspnService::class)->shouldReceive('getGame')->once()->andReturn(['header' => [
        'id' => 'past-recovery-synthetic', 'season' => ['year' => 2026], 'competitions' => [[
            'date' => '2026-11-26T20:00:00Z', 'timeValid' => true, 'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        ]],
    ]]);
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026, '--game' => $game->id])
        ->expectsOutput('Game '.$game->id.': verified kickoff excluded after verification (outside future horizon).')->assertSuccessful();
    expect(DB::table('cfb_pipeline_steps')->where('scope', 'game:'.$game->id)->count())->toBe(0);
    Http::assertNothingSent();
    $this->travelBack();
});

it('does not broaden a malformed explicit pipeline game ID into season-wide verification', function (string $id) {
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'status' => 'STATUS_SCHEDULED']);
    $calls = 0;
    $this->mock(SyncGameMetadata::class)->shouldReceive('execute')->zeroOrMoreTimes()->andReturnUsing(function () use (&$calls) {
        $calls++;
        throw new RuntimeException('Synthetic unavailable provider');
    });
    $exit = Artisan::call('cfb:run-pregame-pipeline', ['--season' => 2026, '--game' => $id]);
    expect($calls)->toBe(0)->and($exit)->toBe(1);
    Http::assertNothingSent();
})->with(['0', '1junk', '-1', '999999999999999999999999999999']);

it('refuses an oversized pipeline recovery cohort before any metadata calls', function () {
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.cfb' => true]);
    $raw = Game::factory()->raw(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-01', 'game_time' => '00:00:00']);
    $rows = [];
    for ($id = 0; $id < 201; $id++) {
        $rows[] = [...$raw, 'espn_event_id' => 'bounded-pregame-'.$id];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('cfb_games')->insert($chunk);
    }
    $this->mock(SyncGameMetadata::class)->shouldNotReceive('execute');
    $this->artisan('cfb:run-pregame-pipeline', ['--season' => 2026])
        ->expectsOutput('CFB metadata candidate capacity exceeded; scope recovery to at most 200 games.')->assertFailed();
    Http::assertNothingSent();
});
