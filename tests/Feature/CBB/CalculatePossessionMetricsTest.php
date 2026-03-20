<?php

use App\Actions\CBB\CalculatePossessionMetrics;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamPossessionMetric;

uses()->group('cbb', 'possession-metrics');

it('builds team possession metrics from play by play data', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'period' => 2,
        'home_score' => 5,
        'away_score' => 2,
    ]);

    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $home->id,
        'sequence_number' => 1,
        'period' => 1,
        'clock' => '19:40',
        'play_type' => 'made_shot',
        'play_text' => 'Home made jumper',
        'score_value' => 2,
        'made_shot' => true,
        'home_score' => 2,
        'away_score' => 0,
    ]);
    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $away->id,
        'sequence_number' => 2,
        'period' => 1,
        'clock' => '19:10',
        'play_type' => 'turnover',
        'play_text' => 'Away turnover',
        'is_turnover' => true,
        'home_score' => 2,
        'away_score' => 0,
    ]);
    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $home->id,
        'sequence_number' => 3,
        'period' => 2,
        'clock' => '00:40',
        'play_type' => 'made_shot',
        'play_text' => 'Home made three point jumper',
        'score_value' => 3,
        'made_shot' => true,
        'home_score' => 5,
        'away_score' => 0,
    ]);
    Play::factory()->create([
        'game_id' => $game->id,
        'possession_team_id' => $away->id,
        'sequence_number' => 4,
        'period' => 2,
        'clock' => '00:10',
        'play_type' => 'foul',
        'play_text' => 'Away free throw made',
        'is_foul' => true,
        'home_score' => 5,
        'away_score' => 2,
    ]);

    $rows = app(CalculatePossessionMetrics::class)->execute(2026, $game->id, true);

    expect($rows)->toHaveCount(2);

    $homeMetric = TeamPossessionMetric::query()->where('team_id', $home->id)->where('season', 2026)->first();
    $awayMetric = TeamPossessionMetric::query()->where('team_id', $away->id)->where('season', 2026)->first();

    expect($homeMetric)->not->toBeNull()
        ->and((float) $homeMetric->offensive_points_per_possession)->toBe(2.5)
        ->and((float) $homeMetric->defensive_points_per_possession_allowed)->toBe(1.0)
        ->and((float) $homeMetric->turnover_rate)->toBe(0.0)
        ->and((float) $homeMetric->late_game_offensive_points_per_possession)->toBe(3.0);

    expect($awayMetric)->not->toBeNull()
        ->and((float) $awayMetric->offensive_points_per_possession)->toBe(1.0)
        ->and((float) $awayMetric->turnover_rate)->toBe(0.5)
        ->and((float) $awayMetric->free_throw_trip_rate)->toBe(0.5);
});
