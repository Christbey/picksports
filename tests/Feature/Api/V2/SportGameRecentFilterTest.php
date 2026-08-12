<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns only completed team games before the requested game start', function () {
    Sanctum::actingAs(User::factory()->create());
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();

    $older = Game::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-06-19',
        'game_time' => '19:00:00',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);
    $firstDoubleheaderGame = Game::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-06-20',
        'game_time' => '12:00:00',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);
    $laterSameDay = Game::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-06-20',
        'game_time' => '18:00:00',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);
    Game::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-21',
        'game_time' => '12:00:00',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    $query = http_build_query([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'before_game_at' => '2026-06-20T15:00:00',
        'exclude_game_id' => $laterSameDay->id,
        'per_page' => 5,
    ]);

    $response = $this->getJson("/api/v2/sports/mlb/teams/{$team->id}/games?{$query}")
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 2);

    expect($response->json('data.*.id'))->toBe([
        $firstDoubleheaderGame->id,
        $older->id,
    ]);

    preg_match('/desc="(\d+) queries"/', (string) $response->headers->get('Server-Timing'), $matches);
    expect((int) ($matches[1] ?? PHP_INT_MAX))->toBeLessThanOrEqual(12);
});
