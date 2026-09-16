<?php

use App\Actions\NFL\CalculateTeamMetrics;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Services\NFL\NflTrueEpaReadinessService;
use Illuminate\Support\Collection;
use Mockery as m;

uses()->group('nfl', 'predictions');

it('refreshes only incomplete true EPA metrics for teams on the prediction slate', function () {
    config([
        'nfl.predictions.true_epa.enabled' => true,
        'nfl.predictions.true_epa.backfill_before_generation' => true,
        'nfl.season.types.regular' => 2,
    ]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);
    TeamMetric::query()->create([
        'team_id' => $home->id,
        'season' => 2026,
        'season_type' => 2,
        'offensive_true_epa_per_play' => .12,
        'defensive_true_epa_per_play' => -.04,
        'net_true_epa_per_play' => .16,
    ]);
    $awayMetric = TeamMetric::query()->create([
        'team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'offensive_true_epa_per_play' => null,
        'defensive_true_epa_per_play' => null,
        'net_true_epa_per_play' => null,
    ]);

    $calculator = m::mock(CalculateTeamMetrics::class);
    $calculator->shouldReceive('execute')
        ->once()
        ->with(m::on(fn (Team $team): bool => $team->is($away)), 2026, '2')
        ->andReturnUsing(function () use ($awayMetric) {
            $awayMetric->update([
                'offensive_true_epa_per_play' => .08,
                'defensive_true_epa_per_play' => .01,
                'net_true_epa_per_play' => .07,
            ]);

            return $awayMetric->fresh();
        });

    $result = (new NflTrueEpaReadinessService($calculator))->prepare(new Collection([$game->fresh(['homeTeam', 'awayTeam'])]));

    expect($result)->toMatchArray([
        'enabled' => true,
        'checked' => 2,
        'attempted' => 1,
        'backfilled' => 1,
        'missing' => [],
    ]);
});

it('reports unresolved metrics so generation can persist an explicit safe hold', function () {
    config([
        'nfl.predictions.true_epa.enabled' => true,
        'nfl.predictions.true_epa.backfill_before_generation' => true,
    ]);

    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'season' => 2026,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);
    $calculator = m::mock(CalculateTeamMetrics::class);
    $calculator->shouldReceive('execute')->twice()->andReturnNull();

    $result = (new NflTrueEpaReadinessService($calculator))->prepare(new Collection([$game->fresh(['homeTeam', 'awayTeam'])]));

    expect($result['attempted'])->toBe(2)
        ->and($result['backfilled'])->toBe(0)
        ->and($result['missing'])->toHaveCount(2);
});
