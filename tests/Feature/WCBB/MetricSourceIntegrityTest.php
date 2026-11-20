<?php

use App\Actions\WCBB\CalculateTeamMetrics;
use App\Actions\WCBB\GeneratePrediction;
use App\Models\CalculationRelease;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;
use App\Services\WCBB\Predictions\WcbbFullModelReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbPregameInputReadiness;
use App\Services\WCBB\WcbbMetricSourceEvidence;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-11-17 12:00:00');
    config(['wcbb.prediction.recover_invalid_team_metrics' => false]);
});

afterEach(fn () => $this->travelBack());

function wcbbSourceGame(Team $home, Team $away, array $attributes = []): Game
{
    return Game::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2027, 'season_type' => '2',
        'game_date' => now()->subDay()->toDateString(), 'game_time' => '18:00:00', 'status' => 'STATUS_FINAL',
        'home_score' => 80, 'away_score' => 75, ...$attributes,
    ]);
}

function wcbbEmptySourcePair(Game $game): void
{
    foreach ([$game->home_team_id, $game->away_team_id] as $id) {
        TeamStat::query()->create(['game_id' => $game->id, 'team_id' => $id,
            'team_type' => $id === $game->home_team_id ? 'home' : 'away', 'points' => 0]);
    }
}

it('never replaces an existing metric with zeroes derived from empty paired rows', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = wcbbSourceGame($home, $away);
    wcbbEmptySourcePair($game);
    $existing = TeamMetric::query()->create(['team_id' => $home->id, 'season' => 2027, 'offensive_efficiency' => 0,
        'defensive_efficiency' => 0, 'tempo' => 0, 'games_played' => 1, 'calculation_date' => now()->subDay()->toDateString()]);
    $before = $existing->fresh()->getAttributes();
    expect(app(CalculateTeamMetrics::class)->execute($home, 2027))->toBeNull()
        ->and($existing->fresh()->getAttributes())->toBe($before);
});

it('keeps completed teams with only empty sources held even when no metric was created', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    wcbbEmptySourcePair(wcbbSourceGame($home, $away));
    $upcoming = wcbbSourceGame($home, $away, ['game_date' => now()->addDay()->toDateString(), 'status' => 'STATUS_SCHEDULED']);
    expect(app(CalculateTeamMetrics::class)->execute($home, 2027))->toBeNull()
        ->and(app(GeneratePrediction::class)->metricInputHolds($upcoming))->toBe([
            ['side' => 'home', 'metric_id' => null, 'reason' => 'incomplete_metric_source_stats'],
            ['side' => 'away', 'metric_id' => null, 'reason' => 'incomplete_metric_source_stats'],
        ]);
});

it('preserves existing preseason defaults before any eligible completed game', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = wcbbSourceGame($home, $away, ['game_date' => now()->addDay()->toDateString(), 'status' => 'STATUS_SCHEDULED']);
    expect(app(GeneratePrediction::class)->metricInputHolds($game))->toBe([]);
});

it('rejects partial possession estimates without inventing missing zero counts', function () {
    $evidence = app(WcbbMetricSourceEvidence::class);
    $stat = new TeamStat(['points' => 80, 'possessions' => null, 'field_goals_attempted' => 60,
        'offensive_rebounds' => 8, 'turnovers' => null, 'free_throws_attempted' => 20]);
    expect($evidence->statIsUsable($stat, 80))->toBeFalse();
    $stat->turnovers = 12;
    expect($evidence->statIsUsable($stat, 80))->toBeTrue();
    $stat->possessions = 0;
    expect($evidence->statIsUsable($stat, 80))->toBeFalse();
    $stat->possessions = 70;
    $stat->points = 0;
    expect($evidence->statIsUsable($stat, 80))->toBeFalse()
        ->and($evidence->statIsUsable($stat, 0))->toBeTrue();
});

it('excludes future finals and configured non-analytics seasons from evidence and calculations', function () {
    config(['wcbb.season.analytics_types' => [2, 3]]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    wcbbEmptySourcePair(wcbbSourceGame($home, $away, ['season_type' => '1']));
    wcbbEmptySourcePair(wcbbSourceGame($home, $away, ['game_date' => now()->addDay()->toDateString()]));
    expect(app(WcbbMetricSourceEvidence::class)->coverage($home->id, 2027))->toBe(['completed_games' => 0, 'usable_paired_games' => 0])
        ->and(app(CalculateTeamMetrics::class)->execute($home, 2027))->toBeNull();
});

it('uses a valid prior metric for incomplete current inputs only under the frozen recovery policy', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    wcbbEmptySourcePair(wcbbSourceGame($home, $away));
    $game = wcbbSourceGame($home, $away, ['game_date' => now()->addDay()->toDateString(), 'status' => 'STATUS_SCHEDULED']);
    foreach ([$home, $away] as $team) {
        TeamMetric::query()->create(['team_id' => $team->id, 'season' => 2027, 'offensive_efficiency' => 90,
            'defensive_efficiency' => 95, 'tempo' => 30, 'calculation_date' => now()->toDateString()]);
        TeamMetric::query()->create(['team_id' => $team->id, 'season' => 2026, 'offensive_efficiency' => 100,
            'defensive_efficiency' => 98, 'tempo' => 70, 'calculation_date' => now()->subMonth()->toDateString()]);
    }
    $release = new CalculationRelease(['configuration' => ['forecast' => ['source' => WcbbFullModelReleaseDefinition::FORECAST_SOURCE,
        'configuration' => ['wcbb.prediction' => ['recover_invalid_team_metrics' => true]]]]]);
    $readiness = app(WcbbPregameInputReadiness::class);
    expect($readiness->holds($game))->toHaveCount(2)
        ->and($readiness->metrics($game, $release)['home']->season)->toBe(2026)
        ->and($readiness->holds($game, $release))->toBe([])
        ->and(config('wcbb.prediction.recover_invalid_team_metrics'))->toBeFalse();
});

it('does not certify a captured input with source corrections from after capture', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = wcbbSourceGame($home, $away);
    $capturedAt = now()->subHour()->toImmutable();
    foreach ([[$home, 80], [$away, 75]] as [$team, $points]) {
        TeamStat::query()->create(['team_id' => $team->id, 'game_id' => $game->id, 'team_type' => $team->id === $home->id ? 'home' : 'away',
            'points' => $points, 'possessions' => 70, 'created_at' => $capturedAt->subHour(), 'updated_at' => now()]);
    }
    $evidence = new WcbbMetricSourceEvidence;
    expect($evidence->coverage($home->id, 2027, $capturedAt))->toBe(['completed_games' => 1, 'usable_paired_games' => 0])
        ->and($evidence->coverage($home->id, 2027, now()))->toBe(['completed_games' => 1, 'usable_paired_games' => 1]);
});

it('does not select a future dated metric before its calculation date', function () {
    $home = Team::factory()->create();
    TeamMetric::query()->create(['team_id' => $home->id, 'season' => 2027, 'offensive_efficiency' => 100,
        'defensive_efficiency' => 98, 'tempo' => 70, 'calculation_date' => now()->addDay()->toDateString()]);
    expect((new WcbbMetricSourceEvidence)->metricCandidates($home->id, 2027, now(), now()->addDays(2)->toDateString())['current'])->toBeNull();
});

it('batches readiness at season scale without caching across later evaluations', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $history = wcbbSourceGame($home, $away);
    foreach ([[$home, 80], [$away, 75]] as [$team, $points]) {
        TeamStat::query()->create(['team_id' => $team->id, 'game_id' => $history->id,
            'team_type' => $team->id === $home->id ? 'home' : 'away', 'points' => $points, 'possessions' => 70]);
        TeamMetric::query()->create(['team_id' => $team->id, 'season' => 2027, 'offensive_efficiency' => 100,
            'defensive_efficiency' => 98, 'tempo' => 70, 'calculation_date' => now()->toDateString()]);
    }
    $games = collect();
    for ($i = 0; $i < 265; $i++) {
        $games->push(wcbbSourceGame($home, $away, ['status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDay()->toDateString()]));
    }
    $release = new CalculationRelease(['configuration' => ['forecast' => ['source' => WcbbFullModelReleaseDefinition::FORECAST_SOURCE,
        'configuration' => ['wcbb.prediction' => ['recover_invalid_team_metrics' => true]]]]]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $results = app(WcbbPregameInputReadiness::class)->forGames($games, $release);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($results)->toHaveCount(265)->and(collect($results)->every(fn ($result): bool => $result['holds'] === []))->toBeTrue()
        ->and(count($queries))->toBeLessThanOrEqual(4)
        ->and(collect($queries)->contains(fn ($query): bool => str_contains($query['query'], 'select * from "wcbb_games"')))->toBeFalse();
    TeamStat::query()->where('game_id', $history->id)->where('team_id', $home->id)->update(['possessions' => 0]);
    $next = app(WcbbPregameInputReadiness::class)->forGames($games->take(1), $release);
    expect($next[$games->first()->id]['holds'])->not->toBeEmpty();
});
