<?php

use App\Actions\OddsApi\MLB\SyncOddsForGames;
use App\Console\Commands\MLB\SyncOddsCommand;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use Illuminate\Support\Facades\Artisan;

it('syncs both preseason and regular odds when both mlb season types are in the window', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    Game::factory()->create([
        'game_date' => now()->addDay()->toDateString(),
        'season_type' => (string) config('mlb.season.types.spring_training', 1),
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);
    Game::factory()->create([
        'game_date' => now()->addDays(3)->toDateString(),
        'season_type' => 'Regular Season',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $syncAction = Mockery::mock(SyncOddsForGames::class);
    $syncAction->shouldReceive('execute')
        ->once()
        ->with(7, 'baseball_mlb_preseason')
        ->andReturn(2);
    $syncAction->shouldReceive('execute')
        ->once()
        ->with(7, 'baseball_mlb')
        ->andReturn(3);

    app()->instance(SyncOddsForGames::class, $syncAction);

    Artisan::registerCommand(app(SyncOddsCommand::class));

    $this->artisan('mlb:sync-odds', ['--days' => 7])
        ->expectsOutput('Syncing odds for upcoming games (next 7 days) using sport key(s) [baseball_mlb_preseason, baseball_mlb]...')
        ->expectsOutput('Successfully updated odds for 5 games.')
        ->assertSuccessful();
});
