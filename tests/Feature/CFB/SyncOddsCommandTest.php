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
    $syncAction->shouldReceive('actionableGameCount')
        ->once()
        ->with(7, null)
        ->andReturn(10);

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
    $syncAction->shouldReceive('actionableGameCount')
        ->once()
        ->with(7, null)
        ->andReturn(10);

    app()->instance(SyncOddsForGames::class, $syncAction);
    Artisan::registerCommand(app(SyncOddsCommand::class));

    $this->artisan('cfb:sync-odds', ['--days' => 7])
        ->expectsOutput('Odds match coverage: 1/10 local actionable games (10.0%).')
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
        ->expectsOutput('Odds match coverage: 0/1 local actionable games (0.0%).')
        ->assertFailed();
    expect($game->fresh()->odds_updated_at)->toBeNull();
});
