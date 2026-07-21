<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Coach;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamCoachSeason;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamStat;
use App\Support\NflBetRuleEngine;
use App\Support\NflValidatedSignalCombos;
use Illuminate\Support\Facades\DB;

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

it('uses stored nfl weather to adjust game totals', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.opponent_adjusted_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => true,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
    ]);

    $game = createNflPredictionTestGame();

    GameWeather::query()->create([
        'game_id' => $game->id,
        'provider' => 'test',
        'observed_at' => '2025-10-15 19:00:00',
        'temperature_f' => 28,
        'wind_speed_mph' => 22,
        'wind_gust_mph' => 32,
        'precipitation_inches' => 0.08,
        'is_indoor' => false,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'actual_weather.applied'))->toBeTrue()
        ->and((float) data_get($prediction->model_metadata, 'actual_weather.total_adjustment'))->toBeLessThan(0)
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('wind_under_signal')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('snow_under_signal')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->not->toContain('rain_under_signal')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_code_metadata.wind_under_signal.source'))->toBe('actual_weather')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_code_metadata.wind_under_signal.market_type'))->toBe('total')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_code_metadata.wind_under_signal.is_actionable'))->toBeTrue();
});

it('uses nflverse play by play epa when team metrics are missing', function () {
    config([
        'nfl.predictions.true_epa.enabled' => true,
        'nfl.predictions.nflverse.true_epa_fallback.enabled' => true,
        'nfl.predictions.nflverse.true_epa_fallback.min_games' => 1,
        'nfl.predictions.nflverse.true_epa_fallback.min_plays' => 2,
        'nfl.predictions.nflverse.true_epa_fallback.blend_weight' => 1.0,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.opponent_adjusted_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
    ]);

    $game = createNflPredictionTestGame();
    $home = $game->homeTeam->abbreviation;
    $away = $game->awayTeam->abbreviation;
    $game->update([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-13',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $priorGame = Game::query()->create([
        'espn_event_id' => 'nflverse-epa-prior-'.$game->id,
        'espn_uid' => 'nflverse-epa-prior-uid-'.$game->id,
        'nflverse_game_id' => '2025_18_'.$away.'_'.$home.'_EPA',
        'season' => 2025,
        'week' => 18,
        'season_type' => '2',
        'game_date' => '2026-01-04',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 31,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);

    foreach ([
        [$home, $away, 0.30],
        [$home, $away, 0.20],
        [$away, $home, -0.20],
        [$away, $home, -0.30],
    ] as $index => [$possession, $defense, $epa]) {
        DB::table('nflverse_pbp_plays')->insert([
            'nflverse_play_key' => hash('sha256', 'epa-'.$game->id.'-'.$index),
            'nfl_game_id' => $priorGame->id,
            'nflverse_game_id' => $priorGame->nflverse_game_id,
            'play_id' => (string) ($index + 1),
            'season' => 2025,
            'week' => 18,
            'season_type' => 'REG',
            'home_team' => $home,
            'away_team' => $away,
            'possession_team' => $possession,
            'defense_team' => $defense,
            'play_type' => $index % 2 === 0 ? 'pass' : 'run',
            'epa' => $epa,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'true_epa.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'true_epa.source'))->toBe('nflverse_pbp_plays')
        ->and((float) data_get($prediction->model_metadata, 'true_epa.epa_diff'))->toBeGreaterThan(0.0)
        ->and((float) data_get($prediction->model_metadata, 'epa_component.spread'))->toBeGreaterThan(0.0);
});

it('adds opponent-adjusted efficiency metadata to nfl predictions', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.opponent_adjusted_efficiency.enabled' => true,
        'nfl.predictions.opponent_adjusted_efficiency.min_games' => 1,
        'nfl.predictions.opponent_adjusted_efficiency.blend_weight' => 1.0,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
    ]);

    $game = createNflPredictionTestGame();
    $opponent = Team::query()->create([
        'espn_id' => 'OPP_ADJ_'.$game->id,
        'abbreviation' => 'OA'.$game->id,
        'location' => 'Opponent',
        'name' => 'Adjusted',
    ]);

    $prior = Game::query()->create([
        'espn_event_id' => 'opp-adj-prior-'.$game->id,
        'espn_uid' => 'opp-adj-prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 8,
        'season_type' => 'regular',
        'game_date' => '2025-10-01',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $opponent->id,
        'home_score' => 35,
        'away_score' => 14,
        'status' => 'STATUS_FINAL',
    ]);

    EloRating::query()->create([
        'team_id' => $opponent->id,
        'season' => 2025,
        'week' => 7,
        'date' => '2025-09-30',
        'elo_rating' => 1650,
        'elo_change' => 0,
    ]);

    TeamStat::factory()->create([
        'team_id' => $game->home_team_id,
        'game_id' => $prior->id,
        'team_type' => 'home',
        'total_yards' => 430,
        'third_down_conversions' => 8,
        'third_down_attempts' => 12,
        'red_zone_scores' => 4,
        'red_zone_attempts' => 4,
    ]);
    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $prior->id,
        'team_type' => 'away',
        'total_yards' => 260,
        'third_down_conversions' => 3,
        'third_down_attempts' => 12,
        'red_zone_scores' => 1,
        'red_zone_attempts' => 3,
    ]);

    $awayPrior = Game::query()->create([
        'espn_event_id' => 'opp-adj-away-prior-'.$game->id,
        'espn_uid' => 'opp-adj-away-prior-uid-'.$game->id,
        'season' => 2025,
        'week' => 8,
        'season_type' => 'regular',
        'game_date' => '2025-10-02',
        'game_time' => '12:00:00',
        'home_team_id' => $opponent->id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 24,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
    ]);

    TeamStat::factory()->create([
        'team_id' => $opponent->id,
        'game_id' => $awayPrior->id,
        'team_type' => 'home',
        'total_yards' => 350,
        'third_down_conversions' => 5,
        'third_down_attempts' => 12,
        'red_zone_scores' => 2,
        'red_zone_attempts' => 4,
    ]);
    TeamStat::factory()->create([
        'team_id' => $game->away_team_id,
        'game_id' => $awayPrior->id,
        'team_type' => 'away',
        'total_yards' => 280,
        'third_down_conversions' => 3,
        'third_down_attempts' => 12,
        'red_zone_scores' => 1,
        'red_zone_attempts' => 3,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'opponent_adjusted_efficiency.applied'))->toBeTrue()
        ->and((float) data_get($prediction->model_metadata, 'opponent_adjusted_efficiency.signal_spread'))->toBeGreaterThan(0)
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('opponent_adjusted_efficiency_signal');
});

it('evaluates nfl bet rules from reason codes and trust', function () {
    $result = app(NflBetRuleEngine::class)->evaluate(
        ['strong_model_signal', 'qb_form_home_edge', 'trench_matchup_home_edge'],
        [],
        68,
        2.5,
        null
    );

    expect($result['action'])->toBe('play')
        ->and($result['matched_rules'][0]['name'])->toBe('strong_qb_form_home_trench');
});

it('evaluates expanded nfl bet rule combinations', function () {
    $engine = app(NflBetRuleEngine::class);

    $eliteQb = $engine->evaluate(
        ['strong_model_signal', 'elite_qb_vs_weak_secondary', 'ol_pass_protection_edge', 'explosive_pass_edge'],
        [],
        70,
        2.5,
        null
    );

    $weatherUnder = $engine->evaluate(
        ['slow_pace_under_signal', 'total_weather_suppression', 'wind_under_signal', 'run_heavy_clock_control'],
        [],
        62,
        null,
        -4.0
    );

    $divisionKeyNumber = $engine->evaluate(
        ['division_game_variance', 'key_number_edge_3'],
        [],
        58,
        2.5,
        null
    );

    $marketDisagreement = $engine->evaluate(
        ['model_market_disagreement', 'multi_factor_confluence', 'market_overreaction', 'spread_market_edge'],
        [],
        68,
        4.0,
        null
    );

    $passOverride = $engine->evaluate(
        ['strong_model_signal', 'qb_form_home_edge', 'trench_matchup_home_edge', 'conflicting_signals'],
        [],
        72,
        3.0,
        null
    );

    expect($eliteQb['action'])->toBe('play')
        ->and(collect($eliteQb['matched_rules'])->pluck('name'))->toContain('elite_qb_clean_pocket_mismatch')
        ->and($weatherUnder['action'])->toBe('play')
        ->and(collect($weatherUnder['matched_rules'])->pluck('name'))->toContain('weather_pace_under_confluence')
        ->and($divisionKeyNumber['action'])->toBe('lean')
        ->and(collect($divisionKeyNumber['matched_rules'])->pluck('name'))->toContain('division_dog_key_number')
        ->and($marketDisagreement['action'])->toBe('play')
        ->and(collect($marketDisagreement['matched_rules'])->pluck('name'))->toContain('market_disagreement_with_model_quality')
        ->and($passOverride['action'])->toBe('pass')
        ->and($passOverride['pass_rules'])->toContain('pass_conflicting_or_low_quality');
});

it('matches validated nfl signal combos from reason codes', function () {
    $matches = app(NflValidatedSignalCombos::class)->match([
        'qb_form_signal',
        'recent_matchup_record_context',
        'weak_ol_vs_blitz_heavy_defense',
        'rolling_efficiency_signal',
    ]);

    expect($matches)->not->toBeEmpty()
        ->and($matches[0]['name'])->toBe('qb_form_matchup_pressure_mismatch')
        ->and($matches[0]['winner_hit_rate'])->toBe(74.0)
        ->and($matches[0]['sample_size'])->toBe(123);
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
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
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

it('uses synced nfl depth chart starter as upcoming qb identity', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => true,
        'nfl.predictions.qb_form.min_prior_attempts' => 1,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
    ]);

    $game = createNflPredictionTestGame();
    $game->update(['status' => 'STATUS_SCHEDULED']);

    $homeQb = Player::factory()->create([
        'team_id' => $game->home_team_id,
        'position' => 'QB',
        'full_name' => 'Depth Home QB',
        'experience' => 5,
    ]);
    $awayQb = Player::factory()->create([
        'team_id' => $game->away_team_id,
        'position' => 'QB',
        'full_name' => 'Depth Away QB',
        'experience' => 1,
    ]);

    foreach ([[$game->home_team_id, $homeQb->id], [$game->away_team_id, $awayQb->id]] as [$teamId, $playerId]) {
        DepthChartEntry::query()->create([
            'team_id' => $teamId,
            'player_id' => $playerId,
            'season' => (int) $game->season,
            'espn_depth_chart_id' => 'test-offense',
            'depth_chart_name' => '3WR 1TE',
            'position_slot_key' => 'qb',
            'position_code' => 'QB',
            'position_name' => 'Quarterback',
            'position_display_name' => 'Quarterback',
            'depth_rank' => 1,
            'slot_order' => 1,
            'is_starter' => true,
            'source_updated_at' => now(),
        ]);
    }

    $priorGame = Game::query()->create([
        'espn_event_id' => 'depth-qb-prior-'.$game->id,
        'espn_uid' => 'depth-qb-prior-uid-'.$game->id,
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
        'passing_yards' => 270,
    ]);
    PlayerStat::factory()->create([
        'game_id' => $priorGame->id,
        'team_id' => $game->away_team_id,
        'player_id' => $awayQb->id,
        'passing_attempts' => 30,
        'passing_yards' => 180,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'qb_form.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'qb_form.home.qb_id'))->toBe($homeQb->id)
        ->and(data_get($prediction->model_metadata, 'qb_form.away.qb_id'))->toBe($awayQb->id)
        ->and(data_get($prediction->model_metadata, 'qb_form.home.projected_from_depth_chart'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'qb_form.away.projected_from_depth_chart'))->toBeTrue();
});

it('uses nflverse depth charts and weekly stats as qb form fallback', function () {
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
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
    ]);

    $game = createNflPredictionTestGame();
    $home = $game->homeTeam->abbreviation;
    $away = $game->awayTeam->abbreviation;
    $game->update([
        'season' => 2026,
        'week' => 1,
        'game_date' => '2026-09-13',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $priorGame = Game::query()->create([
        'espn_event_id' => 'nflverse-qb-prior-'.$game->id,
        'espn_uid' => 'nflverse-qb-prior-uid-'.$game->id,
        'nflverse_game_id' => '2025_18_'.$away.'_'.$home,
        'season' => 2025,
        'week' => 18,
        'season_type' => '2',
        'game_date' => '2026-01-04',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 31,
        'away_score' => 17,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);

    DB::table('nflverse_rosters')->insert([
        [
            'nflverse_roster_key' => hash('sha256', 'home-qb-roster'),
            'season' => 2026,
            'team_id' => $game->home_team_id,
            'team' => $home,
            'gsis_id' => '00-HOMEQB',
            'full_name' => 'NFLVerse Home QB',
            'position' => 'QB',
            'years_exp' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nflverse_roster_key' => hash('sha256', 'away-qb-roster'),
            'season' => 2026,
            'team_id' => $game->away_team_id,
            'team' => $away,
            'gsis_id' => '00-AWAYQB',
            'full_name' => 'NFLVerse Away QB',
            'position' => 'QB',
            'years_exp' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('nflverse_depth_charts')->insert([
        [
            'nflverse_depth_chart_key' => hash('sha256', 'home-qb-depth'),
            'season' => 2026,
            'team_id' => $game->home_team_id,
            'team' => $home,
            'gsis_id' => '00-HOMEQB',
            'full_name' => 'NFLVerse Home QB',
            'position' => 'QB',
            'depth_position' => 'QB',
            'formation' => 'Offense',
            'depth_rank' => 1,
            'source_updated_at' => '2026-07-20 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nflverse_depth_chart_key' => hash('sha256', 'away-qb-depth'),
            'season' => 2026,
            'team_id' => $game->away_team_id,
            'team' => $away,
            'gsis_id' => '00-AWAYQB',
            'full_name' => 'NFLVerse Away QB',
            'position' => 'QB',
            'depth_position' => 'QB',
            'formation' => 'Offense',
            'depth_rank' => 1,
            'source_updated_at' => '2026-07-20 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('nflverse_weekly_player_stats')->insert([
        [
            'nflverse_weekly_stat_key' => hash('sha256', 'home-qb-weekly'),
            'nfl_game_id' => $priorGame->id,
            'nflverse_game_id' => $priorGame->nflverse_game_id,
            'season' => 2025,
            'week' => 18,
            'season_type' => 'REG',
            'team_id' => $game->home_team_id,
            'team' => $home,
            'opponent_team_id' => $game->away_team_id,
            'opponent_team' => $away,
            'player_id' => '00-HOMEQB',
            'player_display_name' => 'NFLVerse Home QB',
            'position' => 'QB',
            'passing_attempts' => 30,
            'passing_yards' => 300,
            'passing_touchdowns' => 3,
            'interceptions_thrown' => 0,
            'rushing_yards' => 24,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'nflverse_weekly_stat_key' => hash('sha256', 'away-qb-weekly'),
            'nfl_game_id' => $priorGame->id,
            'nflverse_game_id' => $priorGame->nflverse_game_id,
            'season' => 2025,
            'week' => 18,
            'season_type' => 'REG',
            'team_id' => $game->away_team_id,
            'team' => $away,
            'opponent_team_id' => $game->home_team_id,
            'opponent_team' => $home,
            'player_id' => '00-AWAYQB',
            'player_display_name' => 'NFLVerse Away QB',
            'position' => 'QB',
            'passing_attempts' => 30,
            'passing_yards' => 150,
            'passing_touchdowns' => 0,
            'interceptions_thrown' => 2,
            'rushing_yards' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'qb_form.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'qb_form.home.qb_id'))->toBe('00-HOMEQB')
        ->and(data_get($prediction->model_metadata, 'qb_form.home.projected_from_nflverse_depth_chart'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'qb_form.home.prior_source'))->toBe('nflverse_weekly_player_stats')
        ->and((float) data_get($prediction->model_metadata, 'qb_form.home.prior_yards_per_attempt'))->toBe(10.0)
        ->and((float) data_get($prediction->model_metadata, 'qb_form.away.prior_yards_per_attempt'))->toBe(5.0);
});

it('uses nflverse injury reports with depth rank weighting when active injuries are missing', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => true,
        'nfl.predictions.depth_chart.qb_multiplier' => 3.0,
        'nfl.predictions.depth_chart.starter_multiplier' => 1.5,
    ]);

    $game = createNflPredictionTestGame();
    $home = $game->homeTeam->abbreviation;
    $game->update([
        'season' => 2025,
        'week' => 2,
        'game_date' => '2025-09-14',
    ]);

    DB::table('nflverse_depth_charts')->insert([
        'nflverse_depth_chart_key' => hash('sha256', 'injury-qb-depth'),
        'season' => 2025,
        'team_id' => $game->home_team_id,
        'team' => $home,
        'gsis_id' => '00-INJQB',
        'full_name' => 'Injured Starter',
        'position' => 'QB',
        'depth_position' => 'QB',
        'formation' => 'Offense',
        'depth_rank' => 1,
        'source_updated_at' => '2025-09-10 12:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('nflverse_injuries')->insert([
        'nflverse_injury_key' => hash('sha256', 'injury-qb-out'),
        'season' => 2025,
        'week' => 2,
        'season_type' => 'REG',
        'team_id' => $game->home_team_id,
        'team' => $home,
        'gsis_id' => '00-INJQB',
        'full_name' => 'Injured Starter',
        'position' => 'QB',
        'report_primary_injury' => 'Shoulder',
        'report_status' => 'Out',
        'practice_status' => 'Did Not Participate In Practice',
        'source_updated_at' => '2025-09-12 18:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'depth_chart_injuries.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'depth_chart_injuries.home_nflverse_rows'))->toBe(1)
        ->and(data_get($prediction->model_metadata, 'depth_chart_injuries.nflverse_source'))->toBe('nflverse_injuries')
        ->and((float) data_get($prediction->model_metadata, 'depth_chart_injuries.home_out_weighted'))->toBeGreaterThan(1.0)
        ->and((float) data_get($prediction->model_metadata, 'depth_chart_injuries.spread_adjustment'))->toBeLessThan(0.0);
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
        'nfl.predictions.opponent_adjusted_efficiency.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
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

it('adaptively corrects spread and total bias from prior actual results', function () {
    config([
        'nfl.predictions.true_epa.enabled' => false,
        'nfl.predictions.preseason_signal.enabled' => false,
        'nfl.predictions.market_blend.enabled' => false,
        'nfl.predictions.depth_chart_injuries.enabled' => false,
        'nfl.predictions.rolling_efficiency.enabled' => false,
        'nfl.predictions.opponent_adjusted_efficiency.enabled' => false,
        'nfl.predictions.total_environment.enabled' => false,
        'nfl.predictions.qb_form.enabled' => false,
        'nfl.predictions.line_matchup.enabled' => false,
        'nfl.predictions.contextual_factors.enabled' => false,
        'nfl.predictions.actual_weather.enabled' => false,
        'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
        'nfl.predictions.adaptive_point_calibration.enabled' => true,
        'nfl.predictions.adaptive_point_calibration.min_sample' => 3,
        'nfl.predictions.adaptive_point_calibration.lookback_games' => 10,
        'nfl.predictions.adaptive_point_calibration.trim_fraction' => 0.0,
        'nfl.predictions.adaptive_point_calibration.spread_blend_weight' => 1.0,
        'nfl.predictions.adaptive_point_calibration.total_blend_weight' => 1.0,
        'nfl.predictions.adaptive_point_calibration.max_spread_adjustment' => 5.0,
        'nfl.predictions.adaptive_point_calibration.max_total_adjustment' => 5.0,
    ]);

    $game = createNflPredictionTestGame();

    foreach (range(1, 3) as $index) {
        $priorGame = Game::query()->create([
            'espn_event_id' => 'point-adaptive-prior-'.$game->id.'-'.$index,
            'espn_uid' => 'point-adaptive-prior-uid-'.$game->id.'-'.$index,
            'season' => 2025,
            'week' => $index,
            'season_type' => 'regular',
            'game_date' => '2025-09-0'.$index,
            'game_time' => '12:00:00',
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'home_score' => 20,
            'away_score' => 20,
            'status' => 'STATUS_FINAL',
            'neutral_site' => false,
        ]);

        Prediction::query()->create([
            'game_id' => $priorGame->id,
            'home_elo' => 1575,
            'away_elo' => 1490,
            'predicted_spread' => 7.0,
            'predicted_total' => 50.0,
            'win_probability' => 0.73,
            'confidence_score' => 73.0,
            'actual_spread' => 0,
            'actual_total' => 40,
            'spread_error' => 7.0,
            'total_error' => 10.0,
            'winner_correct' => false,
            'graded_at' => now(),
        ]);
    }

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'adaptive_point_calibration.applied'))->toBeTrue()
        ->and((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.spread_residual'))->toBe(7.0)
        ->and((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.total_residual'))->toBe(10.0)
        ->and((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.spread_adjustment'))->toBe(-5.0)
        ->and((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.total_adjustment'))->toBe(-5.0)
        ->and((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.calibrated_spread'))->toBeLessThan((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.baseline_spread'))
        ->and((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.calibrated_total'))->toBeLessThan((float) data_get($prediction->model_metadata, 'adaptive_point_calibration.baseline_total'));
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
        'nfl.predictions.contextual_factors.same_week_record_lookback_seasons' => 3,
        'nfl.predictions.contextual_factors.new_head_coaches' => [
            2025 => [
                'HCTX' => [
                    'coach' => 'Test Coach',
                    'type' => 'first_time_head_coach',
                    'prior' => 1.0,
                ],
            ],
        ],
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
    Game::query()->create([
        'espn_event_id' => 'context-same-week-prior-'.$game->id,
        'espn_uid' => 'context-same-week-prior-uid-'.$game->id,
        'season' => 2024,
        'week' => 10,
        'season_type' => 'regular',
        'game_date' => '2024-11-10',
        'game_time' => '12:00:00',
        'home_team_id' => $game->home_team_id,
        'away_team_id' => $game->away_team_id,
        'home_score' => 31,
        'away_score' => 14,
        'status' => 'STATUS_FINAL',
        'neutral_site' => false,
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'contextual_factors.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.home_away_strength.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.division_rivalry.is_division_game'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.matchup_records.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.matchup_records.home.h2h.wins'))->toBe(3)
        ->and(data_get($prediction->model_metadata, 'contextual_factors.matchup_records.away.h2h.losses'))->toBe(3)
        ->and(data_get($prediction->model_metadata, 'contextual_factors.same_week_records.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.same_week_records.week'))->toBe(10)
        ->and(data_get($prediction->model_metadata, 'contextual_factors.same_week_records.home.team.wins'))->toBe(1)
        ->and(data_get($prediction->model_metadata, 'contextual_factors.same_week_records.away.team.losses'))->toBe(1)
        ->and(data_get($prediction->model_metadata, 'contextual_factors.weather_total.reason'))->toBe('cold_outdoor_proxy')
        ->and(data_get($prediction->model_metadata, 'contextual_factors.schedule_spot.home.previous_game_date'))->toBe('2025-11-09')
        ->and(data_get($prediction->model_metadata, 'contextual_factors.coaching_prior.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'contextual_factors.coaching_prior.new_head_coaches.home.coach'))->toBe('Test Coach')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.applied'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.trust_score'))->toBeNumeric()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('recent_h2h_record_home_edge')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('recent_division_record_home_edge')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('recent_conference_record_home_edge')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('same_week_record_context')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('same_week_h2h_record_home_edge')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('same_week_opponent_division_record_home_edge')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('same_week_opponent_conference_record_home_edge')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('new_head_coach_context')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('home_new_head_coach')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('new_head_coach_home_edge')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('cold_outdoor_total_proxy')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->not->toContain('snow_under_signal')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->not->toContain('wind_under_signal')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->not->toContain('rain_under_signal')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.bet_classification'))->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.calculated_edge.spread_points'))->not->toBeNull()
        ->and(data_get($prediction->model_metadata, 'analysis_layer.pro_signal_layer.version'))->toBe('nfl-pro-signal-layer-v1')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.pro_signal_layer.football_context.same_week_records.week'))->toBe(10)
        ->and(data_get($prediction->model_metadata, 'analysis_layer.pro_signal_layer.football_context.new_head_coaches.home.coach'))->toBe('Test Coach')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.pro_signal_layer.market_context.key_numbers'))->toContain(5);
});

it('detects new nfl head coaches from synced coach season history', function () {
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
        'nfl.predictions.contextual_factors.new_head_coaches' => [],
    ]);

    $game = createNflPredictionTestGame();

    $oldCoach = Coach::query()->create([
        'espn_id' => 'old-coach-'.$game->id,
        'display_name' => 'Old Coach',
    ]);
    $newCoach = Coach::query()->create([
        'espn_id' => 'new-coach-'.$game->id,
        'display_name' => 'New Coach',
    ]);

    TeamCoachSeason::query()->create([
        'coach_id' => $oldCoach->id,
        'team_id' => $game->home_team_id,
        'season' => 2024,
        'role' => 'head_coach',
    ]);
    TeamCoachSeason::query()->create([
        'coach_id' => $newCoach->id,
        'team_id' => $game->home_team_id,
        'season' => 2025,
        'role' => 'head_coach',
    ]);

    app(GeneratePredictionFromHistoricalElo::class)->execute($game->fresh(['homeTeam', 'awayTeam']));

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();

    expect(data_get($prediction->model_metadata, 'contextual_factors.coaching_prior.new_head_coaches.home.source'))->toBe('espn_coach_history')
        ->and(data_get($prediction->model_metadata, 'contextual_factors.coaching_prior.new_head_coaches.home.coach'))->toBe('New Coach')
        ->and(data_get($prediction->model_metadata, 'contextual_factors.coaching_prior.new_head_coaches.home.previous_coach'))->toBe('Old Coach')
        ->and(data_get($prediction->model_metadata, 'contextual_factors.coaching_prior.new_head_coaches.home.type'))->toBe('head_coach_change')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('new_head_coach_context')
        ->and(data_get($prediction->model_metadata, 'analysis_layer.reason_codes'))->toContain('home_new_head_coach');
});
