<?php

use App\Actions\OddsApi\CFB\SyncOddsForGames;
use App\Console\Commands\CFB\SyncOddsCommand;
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
        ->expectsOutput('Odds match coverage: 10/10 local actionable games (100.0%).')
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
        ->expectsOutput('Odds match coverage: 1/10 local actionable games (10.0%).')
        ->expectsOutput('Odds sync coverage 10.0% is below the required 80.0%; unmatched events require attention.')
        ->assertFailed();
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
