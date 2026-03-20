<?php

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamPossessionMetric;

uses()->group('cbb', 'possession-metrics');

it('calculates possession metrics from the command', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $home->id,
        'sequence_number' => 1,
        'play_text' => 'Home layup good',
        'home_score' => 2,
        'away_score' => 0,
    ]);
    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $away->id,
        'sequence_number' => 2,
        'play_text' => 'Away turnover',
        'is_turnover' => true,
        'home_score' => 2,
        'away_score' => 0,
    ]);

    $this->artisan('cbb:calculate-possession-metrics', ['--season' => 2026, '--game_id' => $game->id, '--rebuild' => true])
        ->assertSuccessful();

    expect(TeamPossessionMetric::query()->where('season', 2026)->count())->toBe(2);
});
