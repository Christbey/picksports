<?php

use App\Events\GameFinalized;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\Prediction;
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
        'home_score' => 110,
        'away_score' => 100,
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

it('does not dispatch game finalized event when status is final but scores are missing', function () {
    Event::fake([GameFinalized::class]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL',
        'home_score' => null,
        'away_score' => null,
    ]);

    app(GameFinalizationDispatcher::class)
        ->dispatchIfFinalizedTransition($game, 'STATUS_IN_PROGRESS');

    Event::assertNotDispatched(GameFinalized::class);
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

it('dispatches game finalized event when a final game gains scores and still has an ungraded prediction', function () {
    Event::fake([GameFinalized::class]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 112,
        'away_score' => 107,
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.5,
        'predicted_total' => 219.5,
        'win_probability' => 0.61,
        'confidence_score' => 61.0,
    ]);

    app(GameFinalizationDispatcher::class)
        ->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');

    Event::assertDispatched(GameFinalized::class, function (GameFinalized $event) use ($game) {
        return $event->sport === 'nba'
            && $event->gameId === $game->id;
    });
});

it('dispatches game finalized event when a final game still has ungraded player props', function () {
    Event::fake([GameFinalized::class]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 101,
        'away_score' => 98,
    ]);

    PlayerProp::query()->create([
        'game_id' => $game->id,
        'player_name' => 'Test Player',
        'market' => 'player_points',
        'bookmaker' => 'draftkings',
        'line' => 24.5,
    ]);

    app(GameFinalizationDispatcher::class)
        ->dispatchIfFinalizedTransition($game, 'STATUS_FINAL');

    Event::assertDispatched(GameFinalized::class, function (GameFinalized $event) use ($game) {
        return $event->sport === 'nba'
            && $event->gameId === $game->id;
    });
});
