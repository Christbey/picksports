<?php

use App\Jobs\ESPN\NBA\FetchGameDetails;
use App\Jobs\ESPN\NBA\FetchGamesFromScoreboard;
use App\Models\NBA\Game;
use App\Services\ESPN\HistoricalBackfillService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

it('requires sync mode for full backfill', function () {
    $this->artisan('espn:backfill-historical', [
        'sport' => 'nba',
        '--season' => 2025,
        '--stage' => 'full',
    ])
        ->expectsOutput('Full historical backfill currently requires --sync so scoreboard shells exist before detail sync begins.')
        ->assertExitCode(1);
});

it('runs full nba backfill through the shared historical service', function () {
    $service = Mockery::mock(HistoricalBackfillService::class);
    $start = Carbon::create(2024, 10, 1)->startOfDay();
    $end = Carbon::create(2025, 6, 30)->endOfDay();
    $games = new Collection([
        new class extends Model
        {
            protected $guarded = [];
        },
        new class extends Model
        {
            protected $guarded = [];
        },
    ]);

    $games[0]->espn_event_id = '401';
    $games[1]->espn_event_id = '402';

    $service->shouldReceive('definition')
        ->once()
        ->with('nba')
        ->andReturn([
            'label' => 'NBA',
            'game_model' => Game::class,
            'scoreboard_job' => FetchGamesFromScoreboard::class,
            'detail_job' => FetchGameDetails::class,
            'season_start_month' => 10,
            'season_end_month' => 6,
        ]);

    $service->shouldReceive('resolveDateRange')
        ->once()
        ->with('nba', 2025, null, null)
        ->andReturn([
            'start' => $start,
            'end' => $end,
        ]);

    $service->shouldReceive('runScoreboardSync')
        ->once()
        ->with('nba', Mockery::on(fn ($value) => $value->equalTo($start)), Mockery::on(fn ($value) => $value->equalTo($end)), true, Mockery::type('callable'))
        ->andReturnUsing(function (string $sport, Carbon $from, Carbon $to, bool $sync, callable $progress): int {
            $progress($from, 1);
            $progress($to, 2);

            return 2;
        });

    $service->shouldReceive('pendingDetailGames')
        ->once()
        ->with('nba', Mockery::on(fn ($value) => $value->equalTo($start)), Mockery::on(fn ($value) => $value->equalTo($end)), true, 0)
        ->andReturn($games);

    $service->shouldReceive('runDetailSyncForGames')
        ->once()
        ->with('nba', $games, true, Mockery::type('callable'))
        ->andReturnUsing(function (string $sport, Collection $games, bool $sync, callable $progress): int {
            foreach ($games as $index => $game) {
                $progress($game, $index + 1);
            }

            return $games->count();
        });

    $this->app->instance(HistoricalBackfillService::class, $service);

    $this->artisan('espn:backfill-historical', [
        'sport' => 'nba',
        '--season' => 2025,
        '--stage' => 'full',
        '--sync' => true,
    ])
        ->expectsOutputToContain('NBA historical backfill: full stage')
        ->expectsOutputToContain('Processed nba 2 scoreboard date(s).')
        ->expectsOutputToContain('Detail games to process: 2 (missing plays/stats only)')
        ->expectsOutputToContain('Processed detail sync for 2 nba game(s).')
        ->assertSuccessful();
});
