<?php

use App\Actions\CFB\CalculateElo;
use App\Actions\CFB\CalculateTeamMetrics;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamStat;
use App\Services\CFB\PlayerAvailabilityImpactService;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Support\Facades\Http;

uses()->group('cfb', 'team-metrics');

beforeEach(function () {
    Http::fake(['api.collegefootballdata.com/*' => Http::response([])]);
    $this->team = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1500,
    ]);
    $this->opponent = Team::factory()->create([
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
        'elo_rating' => 1450,
    ]);
});

it('calculates team metrics from complete final game stats', function () {
    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent->id,
        'home_score' => 31,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::query()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'total_yards' => 420,
        'passing_yards' => 260,
        'rushing_yards' => 160,
        'interceptions' => 0,
        'fumbles_lost' => 0,
    ]);

    TeamStat::query()->create([
        'team_id' => $this->opponent->id,
        'game_id' => $game->id,
        'team_type' => 'away',
        'total_yards' => 300,
        'passing_yards' => 190,
        'rushing_yards' => 110,
        'interceptions' => 1,
        'fumbles_lost' => 1,
    ]);

    $metric = app(CalculateTeamMetrics::class)->execute($this->team, 2026);

    expect($metric)->not->toBeNull()
        ->team_id->toBe($this->team->id)
        ->season->toBe(2026)
        ->offensive_rating->toBe('31.0')
        ->defensive_rating->toBe('17.0')
        ->yards_per_game->toBe('420.0')
        ->yards_allowed_per_game->toBe('300.0');
});

it('does not calculate metrics when completed game stats are incomplete', function () {
    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent->id,
        'home_score' => 31,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::query()->create([
        'team_id' => $this->team->id,
        'game_id' => $game->id,
        'team_type' => 'home',
        'total_yards' => 420,
    ]);

    $metric = app(CalculateTeamMetrics::class)->execute($this->team, 2026);

    expect($metric)->toBeNull()
        ->and(TeamMetric::query()->where('team_id', $this->team->id)->where('season', 2026)->exists())->toBeFalse();
});

it('persists advanced cfb signal metrics from cfbd payloads', function () {
    $this->team->forceFill(['school' => 'Georgia', 'cfbd_team_id' => 61, 'elo_rating' => 1625])->save();

    $service = Mockery::mock(CollegeFootballDataService::class);
    $service->shouldReceive('getWepaTeamSeason')->once()->with(2026)->andReturn([]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')
        ->once()
        ->with(2026, null, null, true)
        ->andReturn([
            [
                'team' => 'Georgia',
                'offense' => [
                    'successRate' => 0.49,
                    'explosiveness' => 1.48,
                    'havoc' => ['total' => 0.12],
                    'lineYards' => 3.25,
                    'stuffRate' => 0.14,
                    'sackRate' => 0.04,
                ],
                'defense' => [
                    'successRate' => 0.36,
                    'explosiveness' => 1.11,
                    'havoc' => ['total' => 0.22],
                ],
            ],
        ]);

    $this->app->instance(CollegeFootballDataService::class, $service);

    $metric = app(CalculateTeamMetrics::class)->execute($this->team->fresh(), 2026);

    expect($metric)->not->toBeNull()
        ->and((float) $metric->offensive_success_rate)->toBe(0.49)
        ->and((float) $metric->defensive_success_rate)->toBe(0.36)
        ->and((float) $metric->net_success_rate)->toBe(0.13)
        ->and((float) $metric->net_explosiveness)->toBe(0.37)
        ->and((float) $metric->net_havoc_rate)->toBe(0.10)
        ->and((float) $metric->offensive_line_rating)->toBeGreaterThan(0.0)
        ->and((float) $metric->qb_environment_rating)->toBeGreaterThan(0.0)
        ->and((float) $metric->defensive_front_rating)->toBeGreaterThan(0.0)
        ->and((float) $metric->rating_consensus)->toBeGreaterThan(0.0)
        ->and($metric->cfbd_advanced_payload)->toHaveKey('offense');
});

it('rebuilds historical metrics without current team ratings or availability leaking backward', function () {
    $this->travelTo(now()->setDate(2026, 9, 19));
    $fcs = Team::factory()->create(['division' => 'FCS', 'elo_rating' => 1900]);
    foreach ([[$this->team, 'FBS'], [$this->opponent, 'FBS'], [$fcs, 'NON_FBS']] as [$team, $subdivision]) {
        app(CfbSeasonAffiliationResolver::class)->ensureForSeason($team, 2025, [
            'subdivision' => $subdivision, 'conference' => 'Test', 'division' => null, 'source' => 'cfbd_fbs_membership',
        ]);
    }
    $this->mock(PlayerAvailabilityImpactService::class)
        ->shouldNotReceive('adjustedTeamRating')->shouldNotReceive('totalAdjustment');
    $first = null;
    foreach ([$this->opponent, $fcs] as $index => $opponent) {
        $game = Game::factory()->create([
            'season' => 2025, 'week' => $index + 1, 'game_date' => '2025-09-'.($index === 0 ? '06' : '13'),
            'home_team_id' => $this->team->id, 'away_team_id' => $opponent->id,
            'home_score' => 31, 'away_score' => 17, 'status' => 'STATUS_FINAL',
        ]);
        $first ??= $game;
        foreach ([[$this->team, 'home'], [$opponent, 'away']] as [$participant, $side]) {
            TeamStat::query()->create(['team_id' => $participant->id, 'game_id' => $game->id, 'team_type' => $side,
                'total_yards' => 350, 'passing_yards' => 200, 'rushing_yards' => 150, 'interceptions' => 0, 'fumbles_lost' => 0]);
        }
    }
    foreach ([[$this->team, 1510, 1530], [$this->opponent, 1480, 1460]] as [$participant, $before, $after]) {
        EloRating::query()->create(['team_id' => $participant->id, 'game_id' => $first->id,
            'season' => 2025, 'week' => 1, 'season_type' => 'regular', 'date' => '2025-09-06',
            'elo_before' => $before, 'elo_rating' => $after, 'elo_change' => $after - $before,
            'model_version' => CalculateElo::MODEL_VERSION]);
    }
    $metric = app(CalculateTeamMetrics::class)->execute($this->team, 2025);
    expect((float) $metric->strength_of_schedule)->toBe(1480.0)
        ->and($metric->injury_adjusted_team_rating)->toBeNull()
        ->and($metric->injury_total_adjustment)->toBeNull()
        ->and((float) $metric->rating_consensus_sources['elo']['value'])->toBe(2.4);
    $baseline = $metric->only(['rating_consensus', 'strength_of_schedule', 'resume_rating', 'injury_adjusted_team_rating', 'injury_total_adjustment']);
    $this->team->update(['elo_rating' => 2100]);
    $this->opponent->update(['elo_rating' => 1000]);
    $fcs->update(['elo_rating' => 1000]);
    $again = app(CalculateTeamMetrics::class)->execute($this->team->fresh(), 2025);
    expect($again->only(array_keys($baseline)))->toBe($baseline);
});

it('imports provider WEPA nested EPA fields and retains independent per-season caches', function () {
    $this->team->update(['cfbd_team_id' => 61, 'school' => 'Georgia']);
    app(CfbSeasonAffiliationResolver::class)->ensureForSeason($this->team, 2025, [
        'subdivision' => 'FBS', 'conference' => 'SEC', 'division' => null, 'source' => 'cfbd_fbs_membership']);
    $service = Mockery::mock(CollegeFootballDataService::class);
    $service->shouldReceive('get')->once()->with('/stats/season', ['year' => 2025])->andReturn([]);
    $service->shouldReceive('getWepaTeamSeason')->once()->with(2025)->andReturn([
        ['year' => 2025, 'teamId' => 61, 'team' => 'Georgia', 'epa' => ['total' => .3], 'epaAllowed' => ['total' => -.1]],
    ]);
    $service->shouldReceive('getWepaTeamSeason')->once()->with(2026)->andReturn([]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')->once()->with(2025, null, null, true)->andReturn([
        ['season' => 2025, 'teamId' => 61, 'offense' => ['successRate' => .5]],
    ]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')->once()->with(2026, null, null, true)->andReturn([
        ['season' => 2026, 'teamId' => 61, 'offense' => ['successRate' => .4]],
    ]);
    $this->app->instance(CollegeFootballDataService::class, $service);
    $action = app(CalculateTeamMetrics::class);
    $old = $action->execute($this->team, 2025);
    $current = $action->execute($this->team, 2026);
    expect((float) $old->cfbd_wepa_offense)->toBe(.3)->and((float) $old->cfbd_wepa_defense)->toBe(-.1)
        ->and((float) $old->cfbd_wepa_net)->toBe(.4)->and((float) $old->offensive_success_rate)->toBe(.5)
        ->and($current->cfbd_wepa_net)->toBeNull()->and((float) $current->offensive_success_rate)->toBe(.4);
});

it('derives overall sack rate from complete stored pass attempts and sacks with provenance', function () {
    $this->team->update(['cfbd_team_id' => 61, 'school' => 'Georgia']);
    $game = Game::factory()->create(['season' => 2026, 'home_team_id' => $this->team->id,
        'away_team_id' => $this->opponent->id, 'home_score' => 31, 'away_score' => 17, 'status' => 'STATUS_FINAL']);
    foreach ([[$this->team, 'home'], [$this->opponent, 'away']] as [$team, $side]) {
        TeamStat::create(['team_id' => $team->id, 'game_id' => $game->id, 'team_type' => $side,
            'total_yards' => 350, 'passing_attempts' => 38, 'sacks_allowed' => 2]);
    }
    $service = Mockery::mock(CollegeFootballDataService::class);
    $service->shouldReceive('getWepaTeamSeason')->once()->andReturn([]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')->once()->andReturn([
        ['season' => 2026, 'teamId' => 61, 'offense' => ['successRate' => .5, 'passingDowns' => ['sackRate' => .25]]],
    ]);
    $this->app->instance(CollegeFootballDataService::class, $service);
    $metric = app(CalculateTeamMetrics::class)->execute($this->team, 2026);
    expect((float) $metric->offensive_sack_rate)->toBe(.05)
        ->and(data_get($metric->cfbd_advanced_payload, '_local_derivations.offensive_sack_rate.complete'))->toBeTrue()
        ->and(data_get($metric->cfbd_advanced_payload, '_local_derivations.offensive_sack_rate.stat_ids'))->toHaveCount(1);
});

it('does not silently overwrite metrics when a configured external request fails', function () {
    config(['services.collegefootballdata.api_key' => 'configured-test-key']);
    $this->team->update(['cfbd_team_id' => 61]);
    $metric = TeamMetric::create(['team_id' => $this->team->id, 'season' => 2026,
        'calculation_date' => now(), 'offensive_success_rate' => .47]);
    $service = Mockery::mock(CollegeFootballDataService::class);
    $service->shouldReceive('getWepaTeamSeason')->twice()->andReturn([]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')->twice()->andThrow(new RuntimeException('HTTP400 invalid boolean'));
    $this->app->instance(CollegeFootballDataService::class, $service);
    expect(fn () => app(CalculateTeamMetrics::class)->refreshExternalMetrics($this->team, 2026))->toThrow(RuntimeException::class, 'metric update withheld');
    expect(fn () => app(CalculateTeamMetrics::class)->execute($this->team, 2026))->toThrow(RuntimeException::class, 'metric update withheld')
        ->and((float) $metric->fresh()->offensive_success_rate)->toBe(.47);
});

it('uses cached historical season sacks allowed aggregates with explicit inputs and no current season substitution', function ($sacks, $expected) {
    $this->team->update(['cfbd_team_id' => 61, 'school' => 'Georgia']);
    app(CfbSeasonAffiliationResolver::class)->ensureForSeason($this->team, 2025, [
        'subdivision' => 'FBS', 'conference' => 'SEC', 'division' => null, 'source' => 'cfbd_fbs_membership']);
    $rows = [];
    foreach (['passAttempts' => 380, 'sacksOpponent' => $sacks, 'games' => 13, 'sacks' => 50] as $key => $value) {
        $rows[] = ['season' => 2025, 'team' => 'Georgia', 'statName' => $key, 'statValue' => $value];
    }
    $service = Mockery::mock(CollegeFootballDataService::class);
    $service->shouldReceive('get')->once()->with('/stats/season', ['year' => 2025])->andReturn($rows);
    $service->shouldReceive('getWepaTeamSeason')->once()->with(2025)->andReturn([]);
    $service->shouldReceive('getWepaTeamSeason')->once()->with(2026)->andReturn([]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')->once()->with(2025, null, null, true)->andReturn([
        ['season' => 2025, 'teamId' => 61, 'offense' => ['successRate' => .5]],
    ]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')->once()->with(2026, null, null, true)->andReturn([
        ['season' => 2026, 'teamId' => 61, 'offense' => ['successRate' => .4]],
    ]);
    $this->app->instance(CollegeFootballDataService::class, $service);
    $action = app(CalculateTeamMetrics::class);
    $metric = $action->execute($this->team, 2025);
    expect($metric->offensive_sack_rate === null ? null : (float) $metric->offensive_sack_rate)->toBe($expected);
    if ($expected !== null) {
        expect(data_get($metric->cfbd_advanced_payload, '_local_derivations.offensive_sack_rate.inputs.sacksOpponent'))->toBe($sacks)
            ->and(data_get($metric->cfbd_advanced_payload, '_local_derivations.offensive_sack_rate.sample_games'))->toBe(13)
            ->and(data_get($metric->cfbd_advanced_payload, '_local_derivations.offensive_sack_rate.source'))->toBe('cfbd_stats_season');
    }
    expect($action->execute($this->team, 2025)->offensive_sack_rate)->toBe($metric->offensive_sack_rate)
        ->and($action->execute($this->team, 2026)->offensive_sack_rate)->toBeNull();
})->with([[20, .05], [0, 0.0], [null, null], [-1, null]]);

it('refreshes only existing external metrics without recalculating local ratings', function () {
    $this->team->update(['cfbd_team_id' => 61]);
    $metric = TeamMetric::create(['team_id' => $this->team->id, 'season' => 2026,
        'calculation_date' => '2026-09-01', 'offensive_rating' => 123.4]);
    $service = Mockery::mock(CollegeFootballDataService::class);
    $service->shouldReceive('getWepaTeamSeason')->once()->andReturn([
        ['year' => 2026, 'teamId' => 61, 'epa' => ['total' => .3], 'epaAllowed' => ['total' => .1]],
    ]);
    $service->shouldReceive('getAdvancedTeamSeasonStats')->once()->andReturn([
        ['season' => 2026, 'teamId' => 61, 'offense' => ['successRate' => .5]],
    ]);
    $this->app->instance(CollegeFootballDataService::class, $service);
    $action = app(CalculateTeamMetrics::class);
    $updated = $action->refreshExternalMetrics($this->team, 2026);
    expect((float) $updated->cfbd_wepa_net)->toBe(.2)
        ->and((float) $updated->offensive_success_rate)->toBe(.5)
        ->and($updated->offensive_rating)->toBe($metric->offensive_rating)
        ->and($updated->calculation_date->toDateString())->toBe('2026-09-01')
        ->and($action->refreshExternalMetrics($this->opponent, 2026))->toBeNull();
});
