<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $user = User::factory()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
});

function depthObservedFixture(string $sport, array $rows, string $position = 'PG'): array
{
    $namespace = strtoupper($sport);
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $playerClass = "App\\Models\\{$namespace}\\Player";
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $statClass = "App\\Models\\{$namespace}\\PlayerStat";
    $entryClass = "App\\Models\\{$namespace}\\DepthChartEntry";
    $team = $teamClass::factory()->create();
    $player = $playerClass::factory()->create(['team_id' => $team->id]);
    $entryClass::create(['team_id' => $team->id, 'player_id' => $player->id, 'season' => 2026,
        'position_slot_key' => strtolower($position), 'position_code' => $position, 'depth_rank' => 1, 'is_starter' => true]);
    foreach ($rows as $row) {
        $game = $gameClass::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $teamClass::factory(),
            'season' => $row['season'] ?? 2026, 'season_type' => 2, 'status' => $row['status'] ?? 'STATUS_FINAL', 'game_date' => '2026-01-01']);
        $statClass::create(['player_id' => $player->id, 'game_id' => $game->id, 'team_id' => $team->id,
            ...array_diff_key($row, ['season' => true, 'status' => true])]);
    }

    return [$team, $player];
}

it('keeps depth chart basketball rate denominators on observed fields and exposes unknown separately from zero', function () {
    [$team] = depthObservedFixture('nba', [['points' => 20, 'rebounds_total' => null, 'assists' => 0],
        ['points' => null, 'rebounds_total' => null, 'assists' => 0], ['points' => 999, 'status' => 'STATUS_SCHEDULED'], ['points' => 777, 'season' => 2025]]);
    $response = $this->getJson("/api/v2/sports/nba/teams/{$team->id}/depth-charts?season=2026&season_type=2")->assertOk();
    $metrics = collect($response->json('data.entries.0.stats.metrics'))->keyBy('key');
    expect($response->json('data.entries.0.stats.games_played'))->toBe(2)
        ->and($metrics['ppg']['value'])->toBe('20.0')->and($metrics['ppg']['sample_size'])->toBe(1)
        ->and($metrics['rpg']['value'])->toBe('-')->and($metrics['rpg']['sample_size'])->toBe(0)
        ->and($metrics['apg']['value'])->toBe('0.0')->and($metrics['apg']['sample_size'])->toBe(2);
});

it('does not fabricate football depth counting totals when the field was never observed', function () {
    [$team] = depthObservedFixture('nfl', [['passing_yards' => null, 'passing_touchdowns' => 0, 'interceptions_thrown' => null]], 'QB');
    $response = $this->getJson("/api/v2/sports/nfl/teams/{$team->id}/depth-charts?season=2026&season_type=2")->assertOk();
    $metrics = collect($response->json('data.entries.0.stats.metrics'))->keyBy('key');
    expect($metrics['pass_yards']['value'])->toBe('-')->and($metrics['pass_td']['value'])->toBe('0')
        ->and($metrics['ints']['value'])->toBe('-');
});

it('pairs observed pitching numerator and true fractional innings without filling missing runs', function () {
    [$team] = depthObservedFixture('mlb', [['stat_type' => 'pitching', 'innings_pitched' => 4 / 3, 'earned_runs' => 1, 'walks_allowed' => 0, 'hits_allowed' => 1],
        ['stat_type' => 'pitching', 'innings_pitched' => 4 / 3, 'earned_runs' => null, 'walks_allowed' => null, 'hits_allowed' => 8]], 'SP');
    $response = $this->getJson("/api/v2/sports/mlb/teams/{$team->id}/depth-charts?season=2026&season_type=2")->assertOk();
    $metrics = collect($response->json('data.entries.0.stats.metrics'))->keyBy('key');
    expect($metrics['era']['value'])->toBe('6.75')->and($metrics['era']['sample_size'])->toBe(1)
        ->and($metrics['whip']['value'])->toBe('0.75')->and($metrics['whip']['sample_size'])->toBe(1)
        ->and($metrics['ip']['value'])->toBe('2.7');
});

it('includes completed full-time samples without treating unknown field values as measured zero', function () {
    [$team] = depthObservedFixture('nba', [['points' => 10, 'status' => 'STATUS_FULL_TIME']]);
    $response = $this->getJson("/api/v2/sports/nba/teams/{$team->id}/depth-charts?season=2026")->assertOk();
    expect($response->json('data.entries.0.stats.games_played'))->toBe(1)
        ->and($response->json('data.entries.0.stats.metrics.0.value'))->toBe('10.0');
});

it('rejects invalid depth chart scope rather than coercing it to a default or broadening the query', function (array $query) {
    [$team] = depthObservedFixture('nba', [['points' => 20]]);
    $this->getJson('/api/v2/sports/nba/teams/'.$team->id.'/depth-charts?'.http_build_query($query))->assertUnprocessable();
})->with([
    'invalid season' => [['season' => 'not-a-season']],
    'zero season' => [['season' => 0]],
    'nested phase' => [['season_type' => ['2']]],
    'unknown phase' => [['season_type' => '0']],
    'invalid cutoff' => [['before_date' => 'not-a-date']],
    'unsupported all' => [['season_type' => 'all']],
]);

it('retains batting rate math on one complete paired sample without inventing missing hits from other games', function () {
    [$team] = depthObservedFixture('mlb', [['stat_type' => 'batting', 'at_bats' => 4, 'hits' => 2, 'walks' => 1, 'doubles' => 1, 'triples' => 0, 'home_runs' => 0],
        ['stat_type' => 'batting', 'at_bats' => 100, 'hits' => null, 'walks' => 0, 'doubles' => 0, 'triples' => 0, 'home_runs' => 0]], 'SS');
    $response = $this->getJson("/api/v2/sports/mlb/teams/{$team->id}/depth-charts?season=2026&season_type=regular")->assertOk();
    $metrics = collect($response->json('data.entries.0.stats.metrics'))->keyBy('key');
    expect($metrics['avg']['value'])->toBe('0.500')->and($metrics['avg']['sample_size'])->toBe(1)
        ->and($metrics['ops']['value'])->toBe('1.350')->and($metrics['ops']['sample_size'])->toBe(1)
        ->and($metrics['hr']['value'])->toBe('0')->and($metrics['hr']['sample_size'])->toBe(2)
        ->and($response->json('data.season_type'))->toBe('2');
});

it('distinguishes genuine zero pitching rates from an absent innings denominator', function (?float $innings, string $rate, int $sample) {
    [$team] = depthObservedFixture('mlb', [['stat_type' => 'pitching', 'innings_pitched' => $innings,
        'earned_runs' => 0, 'walks_allowed' => 0, 'hits_allowed' => 0, 'strikeouts_pitched' => 0]], 'SP');
    $response = $this->getJson("/api/v2/sports/mlb/teams/{$team->id}/depth-charts?season=2026&season_type=Regular%20Season")->assertOk();
    $metrics = collect($response->json('data.entries.0.stats.metrics'))->keyBy('key');
    expect($metrics['era']['value'])->toBe($rate)->and($metrics['whip']['value'])->toBe($rate)
        ->and($metrics['era']['sample_size'])->toBe($sample)->and($metrics['whip']['sample_size'])->toBe($sample)
        ->and($metrics['k']['value'])->toBe('0');
})->with(['observed zero numerator' => [4 / 3, '0.00', 1], 'missing denominator' => [null, '-', 0]]);
