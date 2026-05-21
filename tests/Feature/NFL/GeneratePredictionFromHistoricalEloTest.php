<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamStat;

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

it('does not apply active nfl injuries when return date is before the game date', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.injury_scope.unknown_return_days' => 21,
    ]);

    $game = createNflPredictionTestGame();

    $player = Player::factory()->create([
        'team_id' => $game->home_team_id,
        'position' => 'QB',
    ]);

    DepthChartEntry::query()->create([
        'team_id' => $game->home_team_id,
        'player_id' => $player->id,
        'season' => 2025,
        'position_slot_key' => 'qb',
        'position_code' => 'QB',
        'position_name' => 'Quarterback',
        'position_display_name' => 'Quarterback',
        'espn_athlete_id' => $player->espn_id,
        'depth_rank' => 1,
        'is_starter' => true,
    ]);

    PlayerInjury::query()->create([
        'player_id' => $player->id,
        'team_id' => $game->home_team_id,
        'injury_key' => 'returned-before-game',
        'status' => 'Out',
        'detail' => 'Expected back before kickoff',
        'type' => 'Shoulder',
        'return_date' => '2025-10-01',
        'is_active' => true,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect((float) data_get($prediction->model_metadata, 'depth_chart_injuries.spread_adjustment'))->toBe(0.0)
        ->and(data_get($prediction->model_metadata, 'depth_chart_injuries.home_returned_before_game'))->toBe(1)
        ->and((float) data_get($prediction->model_metadata, 'depth_chart_injuries.home_out_weighted'))->toBe(0.0);
});

it('blends prior-game rolling efficiency without using same-day game stats', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => true,
        'nfl.predictions.rolling_efficiency.min_games' => 1,
        'nfl.predictions.rolling_efficiency.blend_weight' => 1.0,
    ]);

    $game = createNflPredictionTestGame();
    $priorGame = Game::query()->create([
        'espn_event_id' => 'prior-'.$game->id,
        'espn_uid' => 'prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 9,
        'season_type' => 'regular',
        'game_date' => '2025-10-01',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 31,
        'away_score' => 10,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);

    TeamStat::factory()->create([
        'game_id' => $priorGame->id,
        'team_id' => $game->home_team_id,
        'team_type' => 'home',
        'total_yards' => 430,
        'interceptions' => 0,
        'fumbles_lost' => 0,
    ]);
    TeamStat::factory()->create([
        'game_id' => $priorGame->id,
        'team_id' => $game->away_team_id,
        'team_type' => 'away',
        'total_yards' => 260,
        'interceptions' => 2,
        'fumbles_lost' => 1,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'rolling_efficiency.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'rolling_efficiency.home.games'))->toBe(1)
        ->and(data_get($prediction->model_metadata, 'rolling_efficiency.away.games'))->toBe(1)
        ->and((float) data_get($prediction->model_metadata, 'rolling_efficiency.home.avg_margin'))->toBe(21.0)
        ->and((float) $prediction->predicted_spread)->toBeGreaterThan(10.0);
});

it('uses game primary passer identity with only prior qb production for qb form', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => true,
        'nfl.predictions.qb_form.min_prior_attempts' => 1,
        'nfl.predictions.qb_form.blend_weight' => 1.0,
        'nfl.predictions.qb_form.max_qb_score' => 2.0,
        'nfl.predictions.qb_form.full_weight_attempts' => 180,
        'nfl.predictions.qb_form.full_weight_games' => 5,
    ]);

    $game = createNflPredictionTestGame();

    $homeQb = Player::factory()->create([
        'team_id' => $game->home_team_id,
        'position' => 'QB',
        'full_name' => 'Home QB',
    ]);
    $awayQb = Player::factory()->create([
        'team_id' => $game->away_team_id,
        'position' => 'QB',
        'full_name' => 'Away QB',
    ]);

    $priorGame = Game::query()->create([
        'espn_event_id' => 'qb-prior-'.$game->id,
        'espn_uid' => 'qb-prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 9,
        'season_type' => 'regular',
        'game_date' => '2025-10-01',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 24,
        'away_score' => 21,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);

    PlayerStat::factory()->create([
        'game_id' => $priorGame->id,
        'team_id' => $game->home_team_id,
        'player_id' => $homeQb->id,
        'passing_attempts' => 30,
        'passing_yards' => 300,
        'passing_touchdowns' => 3,
        'interceptions_thrown' => 0,
        'sacks_taken' => 1,
        'rushing_yards' => 30,
    ]);
    PlayerStat::factory()->create([
        'game_id' => $priorGame->id,
        'team_id' => $game->away_team_id,
        'player_id' => $awayQb->id,
        'passing_attempts' => 30,
        'passing_yards' => 150,
        'passing_touchdowns' => 0,
        'interceptions_thrown' => 2,
        'sacks_taken' => 4,
        'rushing_yards' => 0,
    ]);

    PlayerStat::factory()->create([
        'game_id' => $game->id,
        'team_id' => $game->home_team_id,
        'player_id' => $homeQb->id,
        'passing_attempts' => 1,
        'passing_yards' => 0,
        'passing_touchdowns' => 0,
        'interceptions_thrown' => 0,
        'sacks_taken' => 0,
    ]);
    PlayerStat::factory()->create([
        'game_id' => $game->id,
        'team_id' => $game->away_team_id,
        'player_id' => $awayQb->id,
        'passing_attempts' => 1,
        'passing_yards' => 500,
        'passing_touchdowns' => 5,
        'interceptions_thrown' => 0,
        'sacks_taken' => 0,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'qb_form.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'qb_form.home.qb_id'))->toBe($homeQb->id)
        ->and(data_get($prediction->model_metadata, 'qb_form.away.qb_id'))->toBe($awayQb->id)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.home.prior_yards_per_attempt'))->toBe(10.0)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.away.prior_yards_per_attempt'))->toBe(5.0)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.home.score'))->toBe(2.0)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.away.score'))->toBe(-2.0)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.sample_weight'))->toBeLessThan(1.0)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.effective_weight'))->toBeLessThan(1.0)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.signal_spread'))->toBeGreaterThan(0.0);
});

it('blends ol versus dl matchup using only prior team line stats', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => true,
        'nfl.predictions.line_matchup.min_games' => 1,
        'nfl.predictions.line_matchup.blend_weight' => 1.0,
    ]);

    $game = createNflPredictionTestGame();
    $homePriorOpponent = Team::query()->create([
        'espn_id' => 'LINE_HOME_OPP_'.$game->id,
        'abbreviation' => 'LHO'.$game->id,
        'location' => 'Line Home Opp',
        'name' => 'Line Home Opponent',
    ]);
    $awayPriorOpponent = Team::query()->create([
        'espn_id' => 'LINE_AWAY_OPP_'.$game->id,
        'abbreviation' => 'LAO'.$game->id,
        'location' => 'Line Away Opp',
        'name' => 'Line Away Opponent',
    ]);

    $homePriorGame = Game::query()->create([
        'espn_event_id' => 'line-home-prior-'.$game->id,
        'espn_uid' => 'line-home-prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 9,
        'season_type' => 'regular',
        'game_date' => '2025-10-01',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $homePriorOpponent->id,
        'home_score' => 24,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);
    $awayPriorGame = Game::query()->create([
        'espn_event_id' => 'line-away-prior-'.$game->id,
        'espn_uid' => 'line-away-prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 9,
        'season_type' => 'regular',
        'game_date' => '2025-10-01',
        'game_time' => '12:00:00',
        'home_team_id' => $awayPriorOpponent->id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 28,
        'away_score' => 10,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);

    TeamStat::factory()->create([
        'game_id' => $homePriorGame->id,
        'team_id' => $game->home_team_id,
        'team_type' => 'home',
        'passing_attempts' => 40,
        'sacks_allowed' => 0,
        'rushing_yards' => 180,
        'rushing_attempts' => 30,
    ]);
    TeamStat::factory()->create([
        'game_id' => $homePriorGame->id,
        'team_id' => $homePriorOpponent->id,
        'team_type' => 'away',
        'passing_attempts' => 40,
        'sacks_allowed' => 5,
        'rushing_yards' => 45,
        'rushing_attempts' => 30,
    ]);

    TeamStat::factory()->create([
        'game_id' => $awayPriorGame->id,
        'team_id' => $awayPriorOpponent->id,
        'team_type' => 'home',
        'passing_attempts' => 40,
        'sacks_allowed' => 0,
        'rushing_yards' => 220,
        'rushing_attempts' => 30,
    ]);
    TeamStat::factory()->create([
        'game_id' => $awayPriorGame->id,
        'team_id' => $game->away_team_id,
        'team_type' => 'away',
        'passing_attempts' => 40,
        'sacks_allowed' => 6,
        'rushing_yards' => 60,
        'rushing_attempts' => 30,
    ]);

    TeamStat::factory()->create([
        'game_id' => $game->id,
        'team_id' => $game->home_team_id,
        'team_type' => 'home',
        'passing_attempts' => 40,
        'sacks_allowed' => 8,
        'rushing_yards' => 40,
        'rushing_attempts' => 20,
    ]);
    TeamStat::factory()->create([
        'game_id' => $game->id,
        'team_id' => $game->away_team_id,
        'team_type' => 'away',
        'passing_attempts' => 40,
        'sacks_allowed' => 0,
        'rushing_yards' => 220,
        'rushing_attempts' => 30,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'line_matchup.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'line_matchup.home.games'))->toBe(1)
        ->and(data_get($prediction->model_metadata, 'line_matchup.away.games'))->toBe(1)
        ->and((float) data_get($prediction->model_metadata, 'line_matchup.home.off_rush_yards_per_attempt'))->toBe(6.0)
        ->and((float) data_get($prediction->model_metadata, 'line_matchup.away.off_sack_allowed_rate'))->toBe(0.15)
        ->and((float) data_get($prediction->model_metadata, 'line_matchup.signal_spread'))->toBeGreaterThan(0.0);
});

it('adaptively shrinks win probability when similar prior confidence has underperformed', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => true,
        'nfl.predictions.adaptive_win_probability_calibration.min_bucket_sample' => 1,
        'nfl.predictions.adaptive_win_probability_calibration.blend_weight' => 1.0,
        'nfl.predictions.adaptive_win_probability_calibration.max_adjustment' => 0.2,
    ]);

    $game = createNflPredictionTestGame();
    $baselineProbability = 1 / (1 + pow(10, (1490.0 - (1575.0 + config('nfl.elo.home_field_advantage'))) / 400));

    foreach (range(1, 3) as $index) {
        $priorGame = Game::query()->create([
            'espn_event_id' => 'adaptive-prior-'.$game->id.'-'.$index,
            'espn_uid' => 'adaptive-prior-uid-'.$game->id.'-'.$index,
            'season' => 2025,
            'week' => $index,
            'season_type' => 'regular',
            'game_date' => '2025-09-0'.$index,
            'game_time' => '12:00:00',
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'home_score' => 14,
            'away_score' => 24,
            'status' => 'STATUS_FINAL',
            'neutral_site' => false,
        ]);

        Prediction::query()->create([
            'game_id' => $priorGame->id,
            'home_elo' => 1575,
            'away_elo' => 1490,
            'predicted_spread' => 5.5,
            'predicted_total' => 44.0,
            'win_probability' => round($baselineProbability, 3),
            'confidence_score' => round(max($baselineProbability, 1 - $baselineProbability) * 100, 2),
            'actual_spread' => -10,
            'actual_total' => 38,
            'winner_correct' => false,
            'graded_at' => now(),
        ]);
    }

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'adaptive_win_probability_calibration.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'adaptive_win_probability_calibration.source'))->toBe('confidence_bucket')
        ->and((float) data_get($prediction->model_metadata, 'adaptive_win_probability_calibration.baseline_win_probability'))->toBeGreaterThan((float) $prediction->win_probability)
        ->and((float) data_get($prediction->model_metadata, 'adaptive_win_probability_calibration.actual_favorite_win_rate'))->toBe(0.0);
});

it('adds contextual factors and analysis metadata to nfl predictions', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => true,
        'nfl.predictions.contextual_factors.home_away_min_games' => 1,
        'nfl.predictions.contextual_factors.coaching_priors' => ['HCTX' => 1.0, 'ACTX' => -1.0],
    ]);

    $game = createNflPredictionTestGame();
    $game->homeTeam->update(['abbreviation' => 'HCTX', 'conference' => 'AFC', 'division' => 'East']);
    $game->awayTeam->update(['abbreviation' => 'ACTX', 'conference' => 'AFC', 'division' => 'East']);
    $game->update([
        'game_date' => '2025-11-15',
        'venue_name' => 'Open Air Stadium',
        'venue_state' => 'NY',
        'odds_data' => [
            'home_team' => 'Home Team',
            'bookmakers' => [[
                'markets' => [[
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Home Team', 'point' => -1.5],
                        ['name' => 'Away Team', 'point' => 1.5],
                    ],
                ]],
            ]],
        ],
    ]);

    Game::query()->create([
        'espn_event_id' => 'context-home-prior-'.$game->id,
        'espn_uid' => 'context-home-prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 9,
        'season_type' => 'regular',
        'game_date' => '2025-11-01',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 28,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);
    Game::query()->create([
        'espn_event_id' => 'context-away-prior-'.$game->id,
        'espn_uid' => 'context-away-prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 10,
        'season_type' => 'regular',
        'game_date' => '2025-11-09',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 20,
        'away_score' => 10,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'contextual_factors.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.home_away_strength.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.division_rivalry.is_division_game'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.weather_total.reason'))->toBe('cold_outdoor_proxy')
        ->and(data_get($prediction->model_metadata, 'contextual_factors.schedule_spot.home.previous_game_date'))->toBe('2025-11-09')
        ->and(data_get($prediction->model_metadata, 'contextual_factors.coaching_prior.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.trust_score'))->toBeNumeric()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.bet_classification'))->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.calculated_edge.spread_points'))->not->toBeNull();
});
