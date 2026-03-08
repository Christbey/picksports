<?php

use App\Events\GameFinalized;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\GameFinalizationDispatcher;
use Illuminate\Support\Facades\Event;

it('dispatches game finalized event when status transitions to final', function () {
    Event::fake([GameFinalized::class]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
    ]);

    app(GameFinalizationDispatcher::class)
        ->dispatchIfFinalizedTransition($game, 'STATUS_IN_PROGRESS');

    Event::assertDispatched(GameFinalized::class, function (GameFinalized $event) use ($game) {
        return $event->sport === 'nba'
            && $event->gameId === $game->id
            && $event->season === 2026
            && $event->gameModelClass === Game::class;
    });
});

it('does not dispatch game finalized event when game was already final', function () {
    Event::fake([GameFinalized::class]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL',
    ]);

    app(GameFinalizationDispatcher::class)
        ->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');

    Event::assertNotDispatched(GameFinalized::class);
});
