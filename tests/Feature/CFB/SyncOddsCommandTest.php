<?php

use App\Actions\OddsApi\CFB\SyncOddsForGames;
use App\Console\Commands\CFB\SyncOddsCommand;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Support\Facades\Artisan;

it('reports healthy cfb odds match coverage', function () {
    $syncAction = Mockery::mock(SyncOddsForGames::class);
    $syncAction->shouldReceive('execute')
        ->once()
        ->with(7, null)
        ->andReturn(10);
    $syncAction->shouldReceive('diagnostics')
        ->once()
        ->with(7, null)
        ->andReturn(cfbOddsDiagnostics(localGames: 10, matchedEvents: 10));

    app()->instance(SyncOddsForGames::class, $syncAction);
    Artisan::registerCommand(app(SyncOddsCommand::class));

    $this->artisan('cfb:sync-odds', ['--days' => 7])
        ->expectsOutput('Odds match coverage: 10/10 offered events (100.0%); 10 local actionable games.')
        ->expectsOutput('Successfully updated odds for 10 games.')
        ->assertSuccessful();
});

it('fails cfb odds sync loudly when match coverage is too low', function () {
    $syncAction = Mockery::mock(SyncOddsForGames::class);
    $syncAction->shouldReceive('execute')
        ->once()
        ->with(7, null)
        ->andReturn(1);
    $syncAction->shouldReceive('diagnostics')
        ->once()
        ->with(7, null)
        ->andReturn(cfbOddsDiagnostics(localGames: 10, matchedEvents: 1));

    app()->instance(SyncOddsForGames::class, $syncAction);
    Artisan::registerCommand(app(SyncOddsCommand::class));

    $this->artisan('cfb:sync-odds', ['--days' => 7])
        ->expectsOutput('Odds match coverage: 1/10 offered events (10.0%); 10 local actionable games.')
        ->expectsOutput('Odds sync coverage 10.0% is below the required 80.0%; unmatched events require attention.')
        ->assertFailed();
});

it('reports zero coverage with a single provider lookup when the provider returns no data', function () {
    $game = Game::factory()->create([
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => Team::factory(),
        'away_team_id' => Team::factory(),
        'game_date' => now()->addDay()->toDateString(),
        'game_time' => '18:00:00',
    ]);
    $this->mock(OddsApiService::class)->shouldReceive('getOdds')
        ->once()->with('americanfootball_ncaaf')->andReturnNull();
    Artisan::registerCommand(app(SyncOddsCommand::class));

    $this->artisan('cfb:sync-odds', ['--days' => 7])
        ->expectsOutput('Odds match coverage: 0/0 offered events (0.0%); 1 local actionable games.')
        ->assertFailed();
    expect($game->fresh()->odds_updated_at)->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function cfbOddsDiagnostics(int $localGames, int $matchedEvents): array
{
    return [
        'sport_key' => 'americanfootball_ncaaf',
        'days_ahead' => 7,
        'local_games' => $localGames,
        'local_games_with_odds' => $matchedEvents,
        'api_events' => $localGames,
        'in_window_events' => $localGames,
        'matched_events' => $matchedEvents,
        'unmatched_events' => [],
    ];
}

it('does not treat local games without offered markets as unmatched provider events', function () {
    $syncAction = Mockery::mock(SyncOddsForGames::class);
    $syncAction->shouldReceive('execute')->once()->with(7, null)->andReturn(66);
    $syncAction->shouldReceive('diagnostics')->once()->with(7, null)
        ->andReturn([...cfbOddsDiagnostics(126, 66), 'in_window_events' => 66]);
    app()->instance(SyncOddsForGames::class, $syncAction);
    Artisan::registerCommand(app(SyncOddsCommand::class));
    $this->artisan('cfb:sync-odds')->expectsOutput('Odds match coverage: 66/66 offered events (100.0%); 126 local actionable games.')->assertSuccessful();
});

it('still fails an empty provider response when local actionable games exist', function () {
    $syncAction = Mockery::mock(SyncOddsForGames::class);
    $syncAction->shouldReceive('execute')->once()->with(7, null)->andReturn(0);
    $syncAction->shouldReceive('diagnostics')->once()->with(7, null)
        ->andReturn([...cfbOddsDiagnostics(10, 0), 'in_window_events' => 0]);
    app()->instance(SyncOddsForGames::class, $syncAction);
    Artisan::registerCommand(app(SyncOddsCommand::class));
    $this->artisan('cfb:sync-odds')->assertFailed();
});
