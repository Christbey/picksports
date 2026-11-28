<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

it('imports an unknown nflverse kickoff without inventing a midnight instant', function (string $clock, string $date = '2026-09-10') {
    Http::preventStrayRequests();
    Team::factory()->create(['abbreviation' => 'DEN']);
    Team::factory()->create(['abbreviation' => 'KC']);
    $path = tempnam(sys_get_temp_dir(), 'picksports-unknown-clock-');
    try {
        file_put_contents($path, "game_id,season,game_type,week,gameday,gametime,away_team,home_team,away_score,home_score\n2026_01_DEN_KC,2026,REG,1,{$date},{$clock},DEN,KC,0,24\n");
        $this->artisan('nfl:import-nflverse-schedules', ['file' => $path])->assertExitCode(0);
        $game = Game::query()->where('nflverse_game_id', '2026_01_DEN_KC')->firstOrFail();
        expect($game->getRawOriginal('game_time'))->toBeNull();
        expect($game->sportEvent->starts_at)->toBeNull();
        Http::assertNothingSent();
    } finally {
        unlink($path);
    }
})->with(['', '26:99', ['01:30', '2026-11-01'], ['02:30', '2026-03-08']]);

it('keeps a legitimate explicitly observed UTC midnight when importing a supported Eastern clock', function () {
    Http::preventStrayRequests();
    Team::factory()->create(['abbreviation' => 'DEN']);
    Team::factory()->create(['abbreviation' => 'KC']);
    $path = tempnam(sys_get_temp_dir(), 'picksports-real-midnight-');
    try {
        file_put_contents($path, "game_id,season,game_type,week,gameday,gametime,away_team,home_team,away_score,home_score\n2026_01_DEN_KC,2026,REG,1,2026-09-10,20:00,DEN,KC,0,24\n");
        $this->artisan('nfl:import-nflverse-schedules', ['file' => $path])->assertExitCode(0);
        $game = Game::query()->where('nflverse_game_id', '2026_01_DEN_KC')->firstOrFail();
        expect($game->getRawOriginal('game_time'))->toBe('00:00:00');
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v2/sports/nfl/games/'.$game->id)->assertOk()->assertJsonPath('data.starts_at', '2026-09-11T00:00:00+00:00');
        Http::assertNothingSent();
    } finally {
        unlink($path);
    }
});

it('masks only identifiable old nflverse placeholder clocks on reads without rewriting the stored row', function () {
    Http::preventStrayRequests();
    $id = '2026_01_DEN_KC';
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'nflverse_game_id' => $id, 'espn_event_id' => 'nflverse:'.$id, 'game_date' => '2026-09-10', 'game_time' => '00:00:00', 'odds_data' => ['event_id' => 'nflverse:'.$id, 'commence_time' => null, 'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']]]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/nfl/games/'.$game->id)->assertOk()->assertJsonPath('data.starts_at', null)->assertJsonPath('data.game_time', null)->assertJsonPath('data.game_date', '2026-09-10');
    expect($game->fresh()->getRawOriginal('game_time'))->toBe('00:00:00');
    Http::assertNothingSent();
});

it('accepts an explicitly unknown stored clock without a non-null database default', function () {
    $column = collect(Schema::getColumns('nfl_games'))->firstWhere('name', 'game_time');
    expect($column['nullable'])->toBeTrue();
    expect(strtolower($column['type_name']))->toBe('time');
    expect($column['default'])->toBeNull();
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-09-10', 'game_time' => null]);
    expect($game->fresh()->getRawOriginal('game_time'))->toBeNull();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/nfl/games/'.$game->id)->assertOk()->assertJsonPath('data.starts_at', null)->assertJsonPath('data.game_time', null);
});

it('retains a coherent known midnight tuple on same day omission and refuses an unverified reschedule', function () {
    Http::preventStrayRequests();
    Team::factory()->create(['abbreviation' => 'DEN']);
    Team::factory()->create(['abbreviation' => 'KC']);
    $path = tempnam(sys_get_temp_dir(), 'picksports-refresh-clock-');
    $header = "game_id,season,game_type,week,gameday,gametime,away_team,home_team,away_score,home_score\n";
    try {
        file_put_contents($path, $header."2026_01_DEN_KC,2026,REG,1,2026-09-10,20:00,DEN,KC,0,24\n");
        $this->artisan('nfl:import-nflverse-schedules', ['file' => $path])->assertExitCode(0);
        $game = Game::query()->where('nflverse_game_id', '2026_01_DEN_KC')->firstOrFail();
        $packet = [$game->getRawOriginal('game_date'), $game->getRawOriginal('game_time'), $game->odds_data, $game->getRawOriginal('odds_updated_at')];
        file_put_contents($path, $header."2026_01_DEN_KC,2026,REG,1,2026-09-10,,DEN,KC,0,24\n");
        $this->artisan('nfl:import-nflverse-schedules', ['file' => $path])->assertExitCode(0);
        $game->refresh();
        expect([$game->getRawOriginal('game_date'), $game->getRawOriginal('game_time'), $game->odds_data, $game->getRawOriginal('odds_updated_at')])->toBe($packet);
        file_put_contents($path, $header."2026_01_DEN_KC,2026,REG,1,2026-09-12,,DEN,KC,0,24\n");
        $this->artisan('nfl:import-nflverse-schedules', ['file' => $path])->expectsOutputToContain('no explicit kickoff')->assertExitCode(1);
        $game->refresh();
        expect([$game->getRawOriginal('game_date'), $game->getRawOriginal('game_time'), $game->odds_data, $game->getRawOriginal('odds_updated_at')])->toBe($packet);
        Http::assertNothingSent();
    } finally {
        unlink($path);
    }
});

it('refuses rollback rather than manufacturing clocks for newly nullable observations', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_time' => null]);
    $migration = require database_path('migrations/2026_11_26_170000_allow_unknown_nfl_game_times.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Forward-only');
    expect($game->fresh()->getRawOriginal('game_time'))->toBeNull();
});
