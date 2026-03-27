<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;

uses()->group('nfl', 'predictions');

function createNflPredictionTestGame(): Game
{
    $suffix = (string) random_int(100000, 999999);

    $homeTeam = Team::query()->create([
        'espn_id' => "HOME_TEST_{$suffix}",
        'abbreviation' => "H{$suffix}",
        'location' => 'Home City',
        'name' => 'Home Team',
    ]);

    $awayTeam = Team::query()->create([
        'espn_id' => "AWAY_TEST_{$suffix}",
        'abbreviation' => "A{$suffix}",
        'location' => 'Away City',
        'name' => 'Away Team',
    ]);

    $game = Game::query()->create([
        'espn_event_id' => "9{$suffix}",
        'espn_uid' => "uid-9{$suffix}",
        'season' => 2025,
        'week' => 10,
        'season_type' => 'regular',
        'game_date' => '2025-10-15',
        'game_time' => '19:20:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);

    EloRating::query()->create([
        'team_id' => $homeTeam->id,
        'game_id' => null,
        'season' => 2025,
        'week' => 9,
        'date' => '2025-10-10',
        'elo_rating' => 1575.0,
        'elo_change' => 0.0,
    ]);

    EloRating::query()->create([
        'team_id' => $awayTeam->id,
        'game_id' => null,
        'season' => 2025,
        'week' => 9,
        'date' => '2025-10-10',
        'elo_rating' => 1490.0,
        'elo_change' => 0.0,
    ]);

    return $game->fresh(['homeTeam', 'awayTeam']);
}

it('falls back to legacy elo-only prediction when true epa metrics are unavailable', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
    ]);

    $game = createNflPredictionTestGame();
    $action = app(GeneratePredictionFromHistoricalElo::class);

    $action->execute($game);
    $legacy = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    expect(data_get($legacy->model_metadata, 'true_epa.enabled'))->toBeFalse()
        ->and(data_get($legacy->model_metadata, 'true_epa.applied'))->toBeFalse()
        ->and(data_get($legacy->model_metadata, 'true_epa.reason'))->toBe('feature_disabled');

    config([
        'nfl.predictions.true_epa.enabled' => true,
    ]);

    $action->execute($game->fresh(['homeTeam', 'awayTeam']));
    $blendedWithoutMetrics = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect((float) $blendedWithoutMetrics->predicted_spread)->toBe((float) $legacy->predicted_spread)
        ->and((float) $blendedWithoutMetrics->win_probability)->toBe((float) $legacy->win_probability)
        ->and((float) $blendedWithoutMetrics->predicted_total)->toBe((float) $legacy->predicted_total)
        ->and(data_get($blendedWithoutMetrics->model_metadata, 'true_epa.enabled'))->toBeTrue()
        ->and(data_get($blendedWithoutMetrics->model_metadata, 'true_epa.applied'))->toBeFalse()
        ->and(data_get($blendedWithoutMetrics->model_metadata, 'true_epa.reason'))->toBe('missing_team_metrics');
});

it('blends nfl true epa into prediction outputs when rollout is enabled', function () {
    config([
        'nfl.predictions.true_epa.enabled' => true,
        'nfl.predictions.true_epa.blend_weight' => 1.0,
        'nfl.predictions.true_epa.spread_points_per_epa' => 14.0,
        'nfl.predictions.true_epa.win_prob_max_adjustment' => 0.12,
        'nfl.predictions.true_epa.win_prob_sensitivity' => 8.0,
        'nfl.predictions.true_epa.total_points_per_epa_component' => 20.0,
        'nfl.predictions.true_epa.min_predicted_total' => 28.0,
        'nfl.predictions.true_epa.max_predicted_total' => 66.0,
    ]);

    $game = createNflPredictionTestGame();

    TeamMetric::query()->create([
        'team_id' => $game->home_team_id,
        'season' => 2025,
        'net_true_epa_per_play' => 0.12,
        'offensive_true_epa_per_play' => 0.10,
        'defensive_true_epa_per_play' => -0.05,
    ]);

    TeamMetric::query()->create([
        'team_id' => $game->away_team_id,
        'season' => 2025,
        'net_true_epa_per_play' => -0.03,
        'offensive_true_epa_per_play' => -0.01,
        'defensive_true_epa_per_play' => 0.06,
    ]);

    $action = app(GeneratePredictionFromHistoricalElo::class);
    $action->execute($game);

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    $homeElo = 1575.0;
    $awayElo = 1490.0;
    $adjustedHomeElo = $homeElo + (float) config('nfl.elo.home_field_advantage');
    $legacyWin = 1 / (1 + pow(10, ($awayElo - $adjustedHomeElo) / 400));
    $legacyTotal = (float) config('nfl.predictions.average_total')
        + ((($homeElo + $awayElo) - (2 * (float) config('nfl.elo.default_rating'))) / 100);

    $epaDiff = 0.12 - (-0.03); // 0.15
    $expectedSpread = max(
        (float) config('nfl.predictions.min_spread'),
        min((float) config('nfl.predictions.max_spread'), $epaDiff * 14.0)
    );

    $expectedWin = $legacyWin + (tanh($epaDiff * 8.0) * 0.12);
    $expectedWin = max(0.01, min(0.99, $expectedWin));

    $homeExpectedDelta = (0.10 - 0.06) * 20.0;
    $awayExpectedDelta = (-0.01 - (-0.05)) * 20.0;
    $expectedTotal = $legacyTotal + $homeExpectedDelta + $awayExpectedDelta;
    $expectedTotal = max(28.0, min(66.0, $expectedTotal));

    expect((float) $prediction->predicted_spread)->toBe(round($expectedSpread, 1))
        ->and((float) $prediction->win_probability)->toBe(round($expectedWin, 3))
        ->and((float) $prediction->predicted_total)->toBe(round($expectedTotal, 1))
        ->and(data_get($prediction->model_metadata, 'true_epa.enabled'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'true_epa.applied'))->toBeTrue()
        ->and((float) data_get($prediction->model_metadata, 'true_epa.weight'))->toBe(1.0)
        ->and((float) data_get($prediction->model_metadata, 'blended.spread'))->toBe(round($expectedSpread, 4));
});

it('weights nfl qb1 injuries more heavily than reserve injuries when depth chart data exists', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.depth_chart.qb_multiplier' => 3.0,
        'nfl.predictions.depth_chart.starter_multiplier' => 1.5,
        'nfl.predictions.depth_chart.rotation_multiplier' => 1.0,
        'nfl.predictions.depth_chart.win_probability_adjustment_per_point' => 0.03,
    ]);

    $game = createNflPredictionTestGame();

    $qb = Player::factory()->create([
        'team_id' => $game->home_team_id,
        'position' => 'QB',
    ]);
    $reserve = Player::factory()->create([
        'team_id' => $game->home_team_id,
        'position' => 'WR',
    ]);

    DepthChartEntry::query()->create([
        'team_id' => $game->home_team_id,
        'player_id' => $qb->id,
        'season' => 2025,
        'position_slot_key' => 'qb',
        'position_code' => 'QB',
        'position_name' => 'Quarterback',
        'position_display_name' => 'Quarterback',
        'espn_athlete_id' => $qb->espn_id,
        'depth_rank' => 1,
        'is_starter' => true,
    ]);

    DepthChartEntry::query()->create([
        'team_id' => $game->home_team_id,
        'player_id' => $reserve->id,
        'season' => 2025,
        'position_slot_key' => 'wr',
        'position_code' => 'WR',
        'position_name' => 'Wide Receiver',
        'position_display_name' => 'Wide Receiver',
        'espn_athlete_id' => $reserve->espn_id,
        'depth_rank' => 4,
        'is_starter' => false,
    ]);

    PlayerInjury::query()->create([
        'player_id' => $reserve->id,
        'team_id' => $game->home_team_id,
        'injury_key' => 'reserve-test',
        'status' => 'Out',
        'detail' => 'Reserve injury',
        'type' => 'Leg',
        'injury_date' => '2025-10-14',
        'is_active' => true,
    ]);

    $action = app(GeneratePredictionFromHistoricalElo::class);
    $action->execute($game->fresh(['homeTeam', 'awayTeam']));
    $reservePrediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    PlayerInjury::query()->delete();

    PlayerInjury::query()->create([
        'player_id' => $qb->id,
        'team_id' => $game->home_team_id,
        'injury_key' => 'qb-test',
        'status' => 'Out',
        'detail' => 'QB injury',
        'type' => 'Shoulder',
        'injury_date' => '2025-10-14',
        'is_active' => true,
    ]);

    $action->execute($game->fresh(['homeTeam', 'awayTeam']));
    $qbPrediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect($qbPrediction->predicted_spread)->toBeLessThan($reservePrediction->predicted_spread)
        ->and($qbPrediction->win_probability)->toBeLessThan($reservePrediction->win_probability)
        ->and(abs((float) data_get($qbPrediction->model_metadata, 'depth_chart_injuries.spread_adjustment')))
            ->toBeGreaterThan(abs((float) data_get($reservePrediction->model_metadata, 'depth_chart_injuries.spread_adjustment')));
});
