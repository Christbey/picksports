<?php

use App\Exceptions\EloAdmissionRefused;
use App\Models\MLB\Game;
use App\Services\Sports\Elo\EloSourceContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Tests\TestCase;

uses(TestCase::class);

it('rejects a per-game pitching source above the ceiling before selecting any identity', function () {
    $connection = Mockery::mock(Connection::class);
    $query = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->with('mlb_player_stats')->once()->andReturn($query);
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('orderBy')->andReturnSelf();
    $query->shouldReceive('limit')->with(1001)->once()->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect(array_fill(0, 1001, (object) [])));
    $game = new Game;
    $game->forceFill(['id' => 1, 'home_team_id' => 1, 'away_team_id' => 2]);
    try {
        app(EloSourceContext::class)->pitchingSource($connection, $game, false);
        $this->fail('An over-budget pitching read selected an identity.');
    } catch (EloAdmissionRefused $exception) {
        expect($exception->reason)->toBe('pitching_observation_budget_exceeded')
            ->and($exception->considered['pitching_observations_considered'])->toBe(1001)
            ->and($exception->limits['pitching_per_game'])->toBe(1000);
    }
});

it('bounds cumulative source reads to the total ceiling plus one refusal sentinel', function () {
    $connection = Mockery::mock(Connection::class);
    $query = Mockery::mock(Builder::class);
    $connection->shouldReceive('table')->with('mlb_player_stats')->andReturn($query);
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('orderBy')->andReturnSelf();
    $limit = 0;
    $query->shouldReceive('limit')->andReturnUsing(function (int $requested) use (&$limit, $query) {
        $limit = $requested;

        return $query;
    });
    // Synthetic parser rows only: these are not provider format or baseball-performance evidence.
    $rows = [];
    for ($id = 1; $id <= 1000; $id++) {
        $rows[] = (object) ['id' => $id, 'player_id' => $id, 'team_id' => $id % 2 ? 1 : 2,
            'innings_pitched' => (string) intdiv(1000 - $id, 2)];
    }
    $query->shouldReceive('get')->andReturnUsing(function () use ($rows, &$limit) {
        return collect(array_slice($rows, 0, $limit));
    });
    $game = new Game;
    $game->forceFill(['id' => 1, 'home_team_id' => 1, 'away_team_id' => 2]);
    $source = app(EloSourceContext::class);
    for ($index = 0; $index < 100; $index++) {
        expect($source->pitchingSource($connection, $game, false))->toHaveCount(2);
    }
    try {
        $source->pitchingSource($connection, $game, false);
        $this->fail('The cumulative source budget was ignored.');
    } catch (EloAdmissionRefused $exception) {
        expect($limit)->toBe(1)->and($exception->considered['pitching_observations_considered'])->toBe(100001)
            ->and($exception->limits['pitching_observations'])->toBe(100000);
    }
});
