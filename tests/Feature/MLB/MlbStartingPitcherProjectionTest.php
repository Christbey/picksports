<?php

use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\Team;
use App\Services\MLB\MlbStartingPitcherProjectionService;
use Carbon\CarbonImmutable;

uses()->group('mlb');

it('advances a five-pitcher rotation through the intervening team schedule', function () {
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $pitchers = collect(range(1, 5))->map(fn (int $slot) => Player::factory()->create([
        'team_id' => $team->id,
        'espn_id' => "rotation-{$slot}",
        'full_name' => "Rotation Pitcher {$slot}",
        'position' => 'SP',
    ]));

    $start = CarbonImmutable::parse('2026-07-01');
    foreach ($pitchers as $index => $pitcher) {
        Game::factory()->regularSeason()->create([
            'season' => 2026,
            'status' => 'STATUS_FINAL',
            'game_date' => $start->addDays($index)->toDateString(),
            'game_time' => '19:05:00',
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'probable_home_pitcher_espn_id' => "pregame-{$index}",
            'actual_home_pitcher_espn_id' => $pitcher->espn_id,
        ]);
    }

    Game::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-07-06',
        'game_time' => '19:05:00',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);
    $target = Game::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-07-07',
        'game_time' => '19:05:00',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
    ]);

    $result = app(MlbStartingPitcherProjectionService::class)->project($target);
    $target->refresh();

    expect($result['changed'])->toBeTrue()
        ->and($target->projected_home_pitcher_espn_id)->toBe('rotation-2')
        ->and($target->projected_home_pitcher_confidence)->toBeGreaterThan(0.7)
        ->and($target->startingPitcherSource('home'))->toBe('rotation_projection')
        ->and($target->resolvedStartingPitcherEspnId('home'))->toBe('rotation-2')
        ->and(data_get($target->pitcher_projection_metadata, 'home.anchor_source'))->toBe('espn_boxscore_confirmed')
        ->and(data_get($target->pitcher_projection_metadata, 'home.games_ahead'))->toBe(2)
        ->and(data_get($target->pitcher_projection_metadata, 'home.rotation_size'))->toBe(5);
});

it('keeps an espn probable authoritative when it differs from the rotation projection', function () {
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();
    $projected = Player::factory()->create([
        'team_id' => $team->id,
        'espn_id' => 'projected-starter',
        'position' => 'SP',
    ]);
    $official = Player::factory()->create([
        'team_id' => $team->id,
        'espn_id' => 'official-starter',
        'position' => 'SP',
    ]);
    $game = Game::factory()->regularSeason()->create([
        'season' => 2026,
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'probable_home_pitcher_espn_id' => $official->espn_id,
        'projected_home_pitcher_espn_id' => $projected->espn_id,
        'projected_home_pitcher_confidence' => 0.72,
    ]);

    expect($game->resolvedStartingPitcherEspnId('home'))->toBe('official-starter')
        ->and($game->startingPitcherSource('home'))->toBe('espn_probable')
        ->and($game->startingPitcherConfidence('home'))->toBe(1.0);
});
