<?php

use App\Models\NFL\Game;
use App\Support\EspnGameStatusResolver;
use App\Support\NflGameStateGuard;
use Tests\TestCase;

uses(TestCase::class);

it('preserves live and final state from stale schedule payloads', function (string $status) {
    $game = new Game(['status' => $status, 'home_score' => 33, 'away_score' => 27, 'period' => 4, 'game_clock' => '0:00']);
    $updates = NflGameStateGuard::preserve($game, ['status' => 'STATUS_SCHEDULED', 'home_score' => 0, 'away_score' => null, 'period' => 0, 'game_clock' => '0:00', 'home_coach' => 'New coach']);
    $game->fill($updates);
    expect($game->status)->toBe($status)->and($game->home_score)->toBe(33)->and($game->away_score)->toBe(27)->and($game->period)->toBe(4)->and($game->home_coach)->toBe('New coach');
})->with(['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_FINAL']);

it('accepts resumed play and final score corrections', function () {
    $game = new Game(['status' => 'STATUS_HALFTIME', 'home_score' => 10, 'away_score' => 7]);
    $updates = NflGameStateGuard::preserve($game, ['status' => 'STATUS_IN_PROGRESS', 'home_score' => 17, 'away_score' => 7]);
    expect($updates['home_score'])->toBe(17)
        ->and(app(EspnGameStatusResolver::class)->resolveForUpdate($game->status, $updates['status'], 'scoreboard', 'nfl'))->toBe('STATUS_IN_PROGRESS');
    $game->status = 'STATUS_FINAL';
    expect(NflGameStateGuard::preserve($game, ['status' => 'STATUS_FINAL', 'home_score' => 20])['home_score'])->toBe(20);
});

it('does not erase populated scores with missing summary fields', function () {
    $game = new Game(['status' => 'STATUS_FINAL', 'home_score' => 33]);
    expect(NflGameStateGuard::preserve($game, ['status' => 'STATUS_FINAL', 'home_score' => null]))->not->toHaveKey('home_score');
});
