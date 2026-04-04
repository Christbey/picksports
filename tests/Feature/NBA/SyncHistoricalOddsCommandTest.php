<?php

use App\Actions\OddsApi\NBA\SyncHistoricalOddsForGames;
use App\Console\Commands\NBA\SyncHistoricalOddsCommand;
use App\Services\OddsApi\Exceptions\OddsApiException;
use Illuminate\Support\Facades\Artisan;

it('runs the nba historical odds command against the shared action', function () {
    $action = Mockery::mock(SyncHistoricalOddsForGames::class);
    $action->shouldReceive('executeHistorical')
        ->once()
        ->andReturn([
            'processed_games' => 12,
            'matched_games' => 10,
            'created_snapshots' => 10,
            'hydrated_current_games' => 0,
            'unmatched_games' => [],
        ]);

    app()->instance(SyncHistoricalOddsForGames::class, $action);

    Artisan::registerCommand(app(SyncHistoricalOddsCommand::class));

    $this->artisan('nba:sync-historical-odds', ['--season' => 2025])
        ->expectsOutput('Syncing historical odds snapshots 24 hour(s) before tip using sport key [basketball_nba]...')
        ->expectsOutput('Processed 12 games, matched 10, created 10 snapshots, hydrated 0 current game rows.')
        ->assertSuccessful();
});

it('fails fast when the odds api returns a quota error', function () {
    $action = Mockery::mock(SyncHistoricalOddsForGames::class);
    $action->shouldReceive('executeHistorical')
        ->once()
        ->andThrow(new OddsApiException('The Odds API returned HTTP 401: Usage quota has been reached. [OUT_OF_USAGE_CREDITS]'));

    app()->instance(SyncHistoricalOddsForGames::class, $action);

    Artisan::registerCommand(app(SyncHistoricalOddsCommand::class));

    $this->artisan('nba:sync-historical-odds', ['--season' => 2025])
        ->expectsOutput('Syncing historical odds snapshots 24 hour(s) before tip using sport key [basketball_nba]...')
        ->expectsOutput('The Odds API returned HTTP 401: Usage quota has been reached. [OUT_OF_USAGE_CREDITS]')
        ->assertFailed();
});
