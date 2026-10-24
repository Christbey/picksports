<?php

use App\Models\NFL\Game;
use App\Models\SportEvent;
use App\Services\Predictions\SnapshotProvenanceResolver;

it('classifies NFL forecasts against canonical kickoff across timezone boundaries', function (string $generatedAt, string $status) {
    config(['app.timezone' => 'America/Chicago']);
    $game = new Game(['game_date' => '2026-09-14', 'game_time' => '00:20:00']);
    $game->setRelation('sportEvent', new SportEvent(['starts_at' => '2026-09-13T19:20:00-05:00']));
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [], $generatedAt);

    expect($result['game_start_at']->utc()->toIso8601String())->toBe('2026-09-14T00:20:00+00:00')
        ->and($result['availability_status'])->toBe($status)
        ->and($result['pregame_safe'])->toBe($status === 'observed_pregame');
})->with([
    ['2026-09-13T19:19:59-05:00', 'observed_pregame'],
    ['2026-09-14T00:20:00Z', 'after_game_start'],
    ['2026-09-13T20:00:00-05:00', 'after_game_start'],
]);

it('uses UTC split NFL fields when no canonical event exists', function () {
    config(['app.timezone' => 'America/Chicago']);
    $game = new Game(['game_date' => '2026-12-14', 'game_time' => '01:20:00']);
    $game->setRelation('sportEvent', null);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [], '2026-12-13T20:00:00-06:00');

    expect($result['game_start_at']->toIso8601String())->toBe('2026-12-14T01:20:00+00:00')
        ->and($result['availability_status'])->toBe('after_game_start');
});

it('does not invent an end-of-day kickoff for NFL games with unknown start times', function () {
    $game = new Game(['game_date' => '2026-09-14']);
    $game->setRelation('sportEvent', null);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [], '2026-09-14T18:00:00Z');

    expect($result['game_start_at'])->toBeNull()
        ->and($result['availability_status'])->toBe('game_start_unknown')
        ->and($result['pregame_safe'])->toBeFalse();
});

it('classifies NBA snapshots by actual UTC tipoff rather than local interpretation of raw fields', function (string $generatedAt, bool $safe) {
    config(['app.timezone' => 'America/Chicago']);
    $game = new App\Models\NBA\Game(['game_date' => '2026-10-24', 'game_time' => '00:00:00']);
    $game->setRelation('sportEvent', null);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [], $generatedAt);
    expect($result['game_start_at']->toIso8601String())->toBe('2026-10-24T00:00:00+00:00')
        ->and($result['pregame_safe'])->toBe($safe);
})->with([['2026-10-23T23:59:59Z', true], ['2026-10-24T00:00:00Z', false], ['2026-10-24T01:00:00Z', false]]);

it('honors canonical NBA start and refuses unknown NBA tipoffs', function () {
    $game = new App\Models\NBA\Game(['game_date' => '2026-10-26', 'game_time' => '22:00:00']);
    $game->setRelation('sportEvent', new SportEvent(['starts_at' => '2026-10-23T19:00:00-05:00']));
    $resolver = app(SnapshotProvenanceResolver::class);
    expect($resolver->resolve($game, [], '2026-10-24T01:00:00Z')['pregame_safe'])->toBeFalse();
    $game->setRelation('sportEvent', null);
    $game->game_time = '';
    expect($resolver->resolve($game, [], '2026-10-24T01:00:00Z')['game_start_at'])->toBeNull();
});
