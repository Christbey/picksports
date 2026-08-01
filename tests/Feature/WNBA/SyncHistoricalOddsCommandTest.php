<?php

use App\Actions\OddsApi\WNBA\SyncHistoricalOddsForGames;
use App\Console\Commands\WNBA\SyncHistoricalOddsCommand;
use App\Services\OddsApi\Exceptions\OddsApiException;
use Illuminate\Support\Facades\Artisan;

it('runs the wnba historical odds command against the shared action', function () {
    $action = Mockery::mock(SyncHistoricalOddsForGames::class);
    $action->shouldReceive('executeHistorical')
        ->once()
        ->andReturn([
            'processed_games' => 11,
            'matched_games' => 9,
            'created_snapshots' => 9,
            'hydrated_current_games' => 0,
            'unmatched_games' => [],
        ]);

    app()->instance(SyncHistoricalOddsForGames::class, $action);

    Artisan::registerCommand(app(SyncHistoricalOddsCommand::class));

    $this->artisan('wnba:sync-historical-odds', ['--season' => 2025])
        ->expectsOutput('Syncing historical odds snapshots 24 hour(s) before tip using sport key [basketball_wnba]...')
        ->expectsOutput('Processed 11 games, matched 9, created 9 snapshots, hydrated 0 current game rows.')
        ->assertSuccessful();
});

it('fails fast when the wnba historical odds api returns a quota error', function () {
    $action = Mockery::mock(SyncHistoricalOddsForGames::class);
    $action->shouldReceive('executeHistorical')
        ->once()
        ->andThrow(new OddsApiException('The Odds API returned HTTP 401: Usage quota has been reached. [OUT_OF_USAGE_CREDITS]'));

    app()->instance(SyncHistoricalOddsForGames::class, $action);

    Artisan::registerCommand(app(SyncHistoricalOddsCommand::class));

    $this->artisan('wnba:sync-historical-odds', ['--season' => 2025])
        ->expectsOutput('Syncing historical odds snapshots 24 hour(s) before tip using sport key [basketball_wnba]...')
        ->expectsOutput('The Odds API returned HTTP 401: Usage quota has been reached. [OUT_OF_USAGE_CREDITS]')
        ->assertFailed();
});
