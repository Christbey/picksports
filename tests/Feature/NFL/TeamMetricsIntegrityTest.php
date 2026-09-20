<?php

use App\Actions\NFL\CalculateTeamMetrics;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamStat;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-09-20 10:00:00');
    $this->team = Team::factory()->create();
    $this->opponent = Team::factory()->create();
    $this->game = function (array $attributes = [], array $stats = []) {
        $game = Game::factory()->create([
            'season' => 2026, 'season_type' => 2, 'game_date' => '2026-09-13',
            'home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
            'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 17, ...$attributes,
        ]);
        TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $this->team->id, 'total_yards' => 400, 'interceptions' => 0, 'fumbles_lost' => 0, ...$stats]);
        TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $this->opponent->id, 'total_yards' => 300, 'interceptions' => 0, 'fumbles_lost' => 0]);

        return $game;
    };
});

it('uses only reported yardage with an explicit denominator and keeps zero valid', function () {
    ($this->game)();
    ($this->game)([], ['total_yards' => null]);
    $action = new CalculateTeamMetrics;
    $metric = $action->execute($this->team, 2026);
    expect($metric->yards_per_game)->toBe('400.0')
        ->and($metric->sample_sizes['yards_per_game'])->toBe(1)
        ->and($metric->games_played)->toBe(2);
    TeamStat::where('team_id', $this->team->id)->update(['total_yards' => null]);
    expect($action->execute($this->team, 2026)->yards_per_game)->toBeNull();
    TeamStat::where('team_id', $this->team->id)->update(['total_yards' => 0]);
    expect($action->execute($this->team, 2026)->yards_per_game)->toBe('0.0');
});

it('withholds incomplete turnover and dependent predictive ratings', function () {
    ($this->game)([], ['interceptions' => null]);
    $metric = (new CalculateTeamMetrics)->execute($this->team, 2026);
    expect($metric->turnover_differential)->toBeNull()
        ->and($metric->predictive_rating)->toBeNull()
        ->and($metric->sample_sizes['turnover_differential'])->toBe(0);
});

it('does not infer consistency or home advantage from one home game', function () {
    ($this->game)();
    $metric = (new CalculateTeamMetrics)->execute($this->team, 2026);
    expect($metric->consistency_rating)->toBeNull()
        ->and($metric->home_advantage_rating)->toBeNull()
        ->and($metric->home_rating)->toBe('7.000')
        ->and($metric->sample_sizes['home'])->toBe(1)
        ->and($metric->sample_sizes['away'])->toBe(0)
        ->and($metric->sample_sizes['last_5'])->toBe(1);
});

it('stores ties and counts them as half wins for luck', function () {
    ($this->game)(['home_score' => 20, 'away_score' => 20]);
    $metric = (new CalculateTeamMetrics)->execute($this->team, 2026);
    expect($metric->wins)->toBe(0)->and($metric->losses)->toBe(0)
        ->and($metric->ties)->toBe(1)->and($metric->games_played)->toBe(1)
        ->and($metric->luck_rating)->toBe('0.000');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/nfl/metrics/teams?season=2026')
        ->assertOk()->assertJsonPath('data.0.record_label', '0-0-1')
        ->assertJsonPath('data.0.games_played', 1)->assertJsonPath('data.0.ties', 1);
});

it('does not silently mix season types in calculations or default API results', function () {
    ($this->game)();
    ($this->game)(['season_type' => 3, 'home_score' => 60]);
    $action = new CalculateTeamMetrics;
    expect($action->execute($this->team, 2026)->points_per_game)->toBe('24.0');
    expect($action->execute($this->team, 2026, 3)->points_per_game)->toBe('60.0');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/nfl/metrics/teams?season=2026')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.season_type', '2');
    $this->getJson('/api/v2/sports/nfl/metrics/teams?season=2026&season_type=3')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.season_type', '3');
});

it('leaves legacy tie counts unknown rather than inventing zero', function () {
    TeamMetric::create(['team_id' => $this->team->id, 'season' => 2026, 'season_type' => 2, 'wins' => 1, 'losses' => 0, 'calculation_date' => now()]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/nfl/metrics/teams?season=2026')->assertOk()
        ->assertJsonPath('data.0.record_label', null)->assertJsonPath('data.0.ties', null)
        ->assertJsonPath('data.0.games_played', null)->assertJsonPath('data.0.record.source', 'recalculation_required');
});

it('does not calculate a final game with unsynchronized scores', function () {
    ($this->game)(['home_score' => null]);
    expect((new CalculateTeamMetrics)->execute($this->team, 2026))->toBeNull();
});

it('reports actual eligible EPA play samples including valid zero', function () {
    $game = ($this->game)();
    foreach ([[true, 0], [true, 1], [false, 10], [true, null]] as [$eligible, $epa]) {
        Play::factory()->create(['game_id' => $game->id, 'possession_team_id' => $this->team->id, 'true_epa' => $epa, 'is_epa_eligible' => $eligible]);
    }
    $metric = (new CalculateTeamMetrics)->execute($this->team, 2026);
    expect($metric->sample_sizes['offensive_epa_plays'])->toBe(2)
        ->and($metric->sample_sizes['defensive_epa_plays'])->toBe(0)
        ->and($metric->offensive_true_epa_per_play)->toBe('0.500')
        ->and($metric->net_true_epa_per_play)->toBeNull();
});
