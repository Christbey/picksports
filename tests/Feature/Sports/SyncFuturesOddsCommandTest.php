<?php

use function Pest\Laravel\artisan;

uses()->group('sports');

it('skips unsupported wcbb futures without calling the odds api', function () {
    artisan('sports:sync-futures-odds --sport=wcbb --season=2026')
        ->expectsOutput('No supported futures sports requested. Supported sports: nba, mlb, nfl, cbb.')
        ->assertSuccessful();
});

it('skips unsupported wcbb historical futures without calling the odds api', function () {
    artisan('sports:sync-historical-futures-odds --sport=wcbb --season=2026 --date=2026-03-01T12:00:00Z')
        ->expectsOutput('No supported historical futures sports requested. Supported sports: nba, mlb, nfl, cbb.')
        ->assertSuccessful();
});
