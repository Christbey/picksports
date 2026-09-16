<?php

use App\Jobs\ESPN\CFB\FetchGameDetails;
use App\Jobs\ESPN\CFB\FetchGamesFromScoreboard;
use App\Models\CFB\Game;
use App\Services\ESPN\HistoricalBackfillService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

it('routes queued historical scoreboards and details to the bulk worker', function () {
    Queue::fake();

    $service = app(HistoricalBackfillService::class);
    $date = Carbon::parse('2025-09-06');

    $service->runScoreboardSync('cfb', $date, $date->copy(), false);
    $service->runDetailSyncForGames(
        'cfb',
        new Collection([new Game(['espn_event_id' => '401752730'])]),
        false,
    );

    Queue::assertPushedOn('sync', FetchGamesFromScoreboard::class);
    Queue::assertPushedOn('sync', FetchGameDetails::class);
});
