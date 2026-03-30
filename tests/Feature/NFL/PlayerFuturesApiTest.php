<?php

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\Sports\FuturesOdd;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('nfl', 'player-futures');

it('returns projected nfl player futures with market odds and probabilities', function () {
    Permission::findOrCreate('view-nfl-predictions', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo('view-nfl-predictions');
    Sanctum::actingAs($user);

    $team = Team::factory()->create([
        'name' => 'Bears',
        'location' => 'Chicago',
        'abbreviation' => 'CHI',
    ]);

    $opponent = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'QB',
        'full_name' => 'Caleb Example',
    ]);

    $backup = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'QB',
        'full_name' => 'Backup Example',
    ]);

    DepthChartEntry::create([
        'team_id' => $team->id,
        'player_id' => $player->id,
        'season' => 2025,
        'position_slot_key' => 'QB1',
        'position_code' => 'QB',
        'position_name' => 'Quarterback',
        'position_display_name' => 'Quarterback',
        'depth_rank' => 1,
        'is_starter' => true,
    ]);

    DepthChartEntry::create([
        'team_id' => $team->id,
        'player_id' => $backup->id,
        'season' => 2025,
        'position_slot_key' => 'QB2',
        'position_code' => 'QB',
        'position_name' => 'Quarterback',
        'position_display_name' => 'Quarterback',
        'depth_rank' => 2,
        'is_starter' => false,
    ]);

    PlayerInjury::create([
        'player_id' => $backup->id,
        'team_id' => $team->id,
        'injury_key' => 'backup-qb-out',
        'status' => 'Out',
        'is_active' => true,
    ]);

    TeamMetric::create([
        'team_id' => $team->id,
        'season' => 2025,
        'predictive_rating' => 1500,
        'future_strength_of_schedule' => 1700,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $opponent->id,
        'season' => 2025,
        'predictive_rating' => 1700,
        'future_strength_of_schedule' => 1700,
        'calculation_date' => now()->toDateString(),
    ]);

    foreach ([1 => 280, 2 => 320] as $week => $yards) {
        $game = Game::factory()->create([
            'season' => 2025,
            'season_type' => config('nfl.season.types.regular'),
            'week' => $week,
            'status' => config('nfl.statuses.final'),
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
        ]);

        PlayerStat::create([
            'player_id' => $player->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'passing_yards' => $yards,
            'passing_touchdowns' => 2,
        ]);
    }

    foreach ([3, 4, 5] as $week) {
        Game::factory()->create([
            'season' => 2025,
            'season_type' => config('nfl.season.types.regular'),
            'week' => $week,
            'status' => config('nfl.statuses.scheduled'),
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
        ]);
    }

    FuturesOdd::create([
        'row_key' => sha1('over'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl',
        'event_id' => 'season-2025',
        'event_name' => 'NFL 2025 Season',
        'bookmaker' => 'draftkings',
        'market_key' => 'player_pass_yds',
        'outcome_name' => 'Over',
        'outcome_description' => $player->full_name,
        'outcome_point' => 4099.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'fetched_at' => now(),
        'nfl_player_id' => $player->id,
    ]);

    FuturesOdd::create([
        'row_key' => sha1('under'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl',
        'event_id' => 'season-2025',
        'event_name' => 'NFL 2025 Season',
        'bookmaker' => 'draftkings',
        'market_key' => 'player_pass_yds',
        'outcome_name' => 'Under',
        'outcome_description' => $player->full_name,
        'outcome_point' => 4099.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'fetched_at' => now(),
        'nfl_player_id' => $player->id,
    ]);

    $response = $this->getJson('/api/v1/nfl/player-futures?season=2025&market=passing_yards');

    $response->assertOk();
    $response->assertJsonPath('meta.season', 2025);
    $response->assertJsonCount(1, 'data');

    $row = $response->json('data.0');

    expect($row['player']['id'])->toBe($player->id);
    expect($row['market'])->toBe('passing_yards');
    expect($row['current_total'])->toBe(600);
    expect($row['games_played'])->toBe(2);
    expect($row['team_games_scheduled'])->toBe(5);
    expect($row['remaining_games'])->toBe(3);
    expect($row['market_odds']['line'])->toBe(4099.5);
    expect($row['market_odds']['over_price'])->toBe(-110);
    expect($row['market_odds']['under_price'])->toBe(-110);
    expect($row['archetype'])->toBe('qb_starter');
    expect($row['current_usage_share'])->toBeGreaterThan(0.9);
    expect($row['role_multiplier'])->toBeGreaterThan(1.0);
    expect($row['competition_injury_boost'])->toBeGreaterThan(1.0);
    expect($row['schedule_adjustment_factor'])->toBeLessThan(1.0);
    expect($row['projected_total'])->toBeGreaterThan(1200);
    expect($row['over_probability'])->toBeNumeric();
    expect($row['under_probability'])->toBeNumeric();
});
