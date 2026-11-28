<?php

use App\Actions\Sports\CalculateBettingValue;
use App\Actions\WCBB\EvaluateCanonicalPrediction;
use App\Actions\WCBB\GenerateCanonicalPrediction;
use App\Actions\WCBB\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\GameOddsSnapshot;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Models\User;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;
use App\Services\OddsApi\MarketQuoteRecorder;
use App\Services\Predictions\Basketball\BasketballPolicyCalculator;
use App\Services\Predictions\Basketball\NeutralAwareBasketballInputSnapshotBuilder;
use App\Services\Predictions\Basketball\NeutralAwareBasketballReleaseDefinition;
use App\Services\Predictions\CanonicalRulesReleaseRegistrar;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseRegistrar;
use App\Services\WCBB\Predictions\WcbbCalculator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses()->group('wcbb', 'predictions');

beforeEach(function () {
    $this->homeTeam = Team::factory()->create([
        'elo_rating' => 1550,
        'school' => 'Iowa',
        'mascot' => 'Hawkeyes',
    ]);
    $this->awayTeam = Team::factory()->create([
        'elo_rating' => 1450,
        'school' => 'UConn',
        'mascot' => 'Huskies',
    ]);
});

it('generates prediction for an upcoming game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->toBeInstanceOf(Prediction::class)
        ->game_id->toBe($game->id);

    expect((float) $prediction->home_elo)->toBe(1550.0);
    expect((float) $prediction->away_elo)->toBe(1450.0);
    expect((float) $prediction->predicted_spread)->toBeGreaterThan(0);
    expect((float) $prediction->win_probability)->toBeGreaterThan(0.5);
});

it('calculates confidence from win probability', function () {
    $strongHome = Team::factory()->create(['elo_rating' => 1700]);
    $weakAway = Team::factory()->create(['elo_rating' => 1300]);

    $game1 = Game::factory()->create([
        'home_team_id' => $strongHome->id,
        'away_team_id' => $weakAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $evenHome = Team::factory()->create(['elo_rating' => 1500]);
    $evenAway = Team::factory()->create(['elo_rating' => 1500]);

    $game2 = Game::factory()->create([
        'home_team_id' => $evenHome->id,
        'away_team_id' => $evenAway->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    $prediction1 = $action->execute($game1);
    $prediction2 = $action->execute($game2);

    expect((float) $prediction1->confidence_score)->toBeGreaterThan((float) $prediction2->confidence_score);

    expect((float) $prediction1->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
    expect((float) $prediction2->confidence_score)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);

    $wp1 = (float) $prediction1->win_probability;
    $expectedConfidence1 = round(max($wp1, 1 - $wp1) * 100, 2);
    expect((float) $prediction1->confidence_score)->toBe($expectedConfidence1);
});

it('does not generate prediction for completed game', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'home_score' => 75,
        'away_score' => 65,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->toBeNull();
});

it('uses team metrics when available', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 110.0,
        'defensive_efficiency' => 100.0,
        'net_rating' => 10.0,
        'tempo' => 70.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 105.0,
        'defensive_efficiency' => 108.0,
        'net_rating' => -3.0,
        'tempo' => 68.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->home_off_eff)->toBe(110.0);
    expect((float) $prediction->home_def_eff)->toBe(100.0);
    expect((float) $prediction->away_off_eff)->toBe(105.0);
    expect((float) $prediction->away_def_eff)->toBe(108.0);
});

it('stores spread components in prediction metadata', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 110.0,
        'defensive_efficiency' => 100.0,
        'net_rating' => 10.0,
        'tempo' => 70.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 105.0,
        'defensive_efficiency' => 108.0,
        'net_rating' => -3.0,
        'tempo' => 68.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction->elo_spread_component)->not->toBeNull();
    expect($prediction->efficiency_spread_component)->not->toBeNull();
    expect($prediction->form_spread_component)->not->toBeNull();

    // ELO component: (1550 + 35 - 1450) / 30 ≈ 4.5
    expect((float) $prediction->elo_spread_component)->toBeGreaterThan(0);

    // Efficiency component: home +10 net, away -3 → should be positive
    expect((float) $prediction->efficiency_spread_component)->toBeGreaterThan(0);
});

it('incorporates recent form from completed games', function () {
    for ($i = 0; $i < 5; $i++) {
        $opponent = Team::factory()->create();
        $completedGame = Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(10 - $i),
            'home_score' => 80,
            'away_score' => 65,
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->homeTeam->id,
            'game_id' => $completedGame->id,
            'team_type' => 'home',
            'points' => 80,
            'possessions' => 70,
            'turnovers' => 12,
            'rebounds' => 40,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'team_type' => 'away',
            'points' => 65,
            'possessions' => 70,
            'turnovers' => 15,
            'rebounds' => 35,
        ]);
    }

    $upcomingGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDay(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($upcomingGame);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->home_recent_form)->toBeGreaterThan(0);
    expect((float) $prediction->form_spread_component)->not->toBeNull();
});

it('applies rest day advantage when home team is rested', function () {
    $opponent = Team::factory()->create();

    Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $opponent->id,
        'status' => 'STATUS_FINAL',
        'season' => 2026,
        'game_date' => now()->subDays(3),
    ]);

    Game::factory()->create([
        'home_team_id' => $opponent->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'season' => 2026,
        'game_date' => now()->subDay(),
    ]);

    $upcomingGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDay(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($upcomingGame);

    expect($prediction)->not->toBeNull();
    expect($prediction->rest_days_home)->toBeGreaterThan($prediction->rest_days_away);

    $evenGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2025,
        'game_date' => now()->addDays(2),
    ]);

    $evenPrediction = $action->execute($evenGame);

    expect((float) $prediction->predicted_spread)->toBeGreaterThanOrEqual((float) $evenPrediction->predicted_spread);
});

it('converts the bookmaker handicap before blending the market spread', function (float $homeLine) {
    config()->set('wcbb.prediction.model_weight_with_vegas', 0.0);
    config()->set('wcbb.prediction.vegas_weight', 1.0);
    config()->set('wcbb.prediction.true_epa.enabled', false);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => [
            'bookmakers' => [
                [
                    'key' => 'draftkings',
                    'title' => 'DraftKings',
                    'markets' => [
                        [
                            'key' => 'h2h',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'price' => -200],
                                ['name' => $this->awayTeam->school, 'price' => 170],
                            ],
                        ],
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                ['name' => $this->homeTeam->school, 'point' => $homeLine, 'price' => -110],
                                ['name' => $this->awayTeam->school, 'point' => -$homeLine, 'price' => -110],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->vegas_spread)->toBe($homeLine)
        ->and((float) $prediction->predicted_spread)->toBe(-$homeLine)
        ->and((float) $prediction->win_probability > 0.5)->toBe($homeLine < 0);
})->with([-4.5, 4.5]);

it('generates prediction without vegas spread when no odds data', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'odds_data' => null,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect($prediction->vegas_spread)->toBeNull();
});

it('stores turnover and rebound adjustments', function () {
    for ($i = 0; $i < 3; $i++) {
        $opponent = Team::factory()->create();

        $completedGame = Game::factory()->create([
            'home_team_id' => $this->homeTeam->id,
            'away_team_id' => $opponent->id,
            'status' => 'STATUS_FINAL',
            'season' => 2026,
            'game_date' => now()->subDays(5 - $i),
        ]);

        TeamStat::factory()->create([
            'team_id' => $this->homeTeam->id,
            'game_id' => $completedGame->id,
            'turnovers' => 10,
            'rebounds' => 45,
        ]);

        TeamStat::factory()->create([
            'team_id' => $opponent->id,
            'game_id' => $completedGame->id,
            'turnovers' => 18,
            'rebounds' => 33,
        ]);
    }

    $upcomingGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => now()->addDay(),
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($upcomingGame);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->turnover_diff_adj)->toBeGreaterThan(0);
    expect((float) $prediction->rebound_margin_adj)->toBeGreaterThan(0);
});

it('ensemble weights sum to one', function () {
    $config = config('wcbb.prediction');
    $sum = $config['elo_weight'] + $config['efficiency_weight'] + $config['form_weight'];

    expect($sum)->toBe(1.0);
});

it('falls back gracefully when no recent form data exists', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;
    $prediction = $action->execute($game);

    expect($prediction)->not->toBeNull();
    expect((float) $prediction->form_spread_component)->toBe(
        config('wcbb.prediction.home_court_points')
    );
});

it('applies true epa blend metadata when enabled and metrics are available', function () {
    config()->set('wcbb.prediction.true_epa.enabled', true);
    config()->set('wcbb.prediction.true_epa.blend_weight', 1.0);
    config()->set('wcbb.prediction.true_epa.spread_points_per_epa', 15.0);

    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    TeamMetric::create([
        'team_id' => $this->homeTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 108.0,
        'defensive_efficiency' => 98.0,
        'net_rating' => 10.0,
        'tempo' => 69.0,
        'strength_of_schedule' => 1500.0,
        'offensive_true_epa_per_play' => 0.110,
        'defensive_true_epa_per_play' => -0.030,
        'net_true_epa_per_play' => 0.080,
        'calculation_date' => now()->toDateString(),
    ]);

    TeamMetric::create([
        'team_id' => $this->awayTeam->id,
        'season' => 2026,
        'offensive_efficiency' => 102.0,
        'defensive_efficiency' => 103.0,
        'net_rating' => -1.0,
        'tempo' => 68.0,
        'strength_of_schedule' => 1500.0,
        'offensive_true_epa_per_play' => 0.040,
        'defensive_true_epa_per_play' => 0.020,
        'net_true_epa_per_play' => -0.020,
        'calculation_date' => now()->toDateString(),
    ]);

    $prediction = (new GeneratePrediction)->execute($game);

    expect($prediction)->not->toBeNull();
    expect(data_get($prediction->model_metadata, 'true_epa.true_epa_enabled'))->toBeTrue()
        ->and(data_get($prediction->model_metadata, 'true_epa.true_epa_applied'))->toBeTrue()
        ->and((float) data_get($prediction->model_metadata, 'true_epa.true_epa_diff'))->toBe(0.1)
        ->and(data_get($prediction->model_metadata, 'true_epa.true_epa_total_reason'))->toBe('applied');
});

it('updates existing prediction instead of creating duplicate', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $action = new GeneratePrediction;

    $prediction1 = $action->execute($game);
    expect(Prediction::count())->toBe(1);

    $this->homeTeam->update(['elo_rating' => 1600]);
    $game->refresh();

    $prediction2 = $action->execute($game);

    expect(Prediction::count())->toBe(1);
    expect($prediction2->id)->toBe($prediction1->id);
    expect((float) $prediction2->home_elo)->toBe(1600.0);
});

it('keeps later completed games out of an earlier WCBB projection', function () {
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => '2025-11-14',
    ]);
    $action = new GeneratePrediction;
    $before = $action->execute($game);

    $laterGame = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_FINAL',
        'season' => $game->season,
        'game_date' => '2025-11-15',
        'home_score' => 100,
        'away_score' => 45,
    ]);

    TeamStat::factory()->create([
        'game_id' => $laterGame->id,
        'team_id' => $this->homeTeam->id,
        'points' => 100,
        'possessions' => 70,
        'turnovers' => 4,
        'rebounds' => 50,
    ]);
    TeamStat::factory()->create([
        'game_id' => $laterGame->id,
        'team_id' => $this->awayTeam->id,
        'points' => 45,
        'possessions' => 70,
        'turnovers' => 24,
        'rebounds' => 20,
    ]);

    $after = $action->execute($game);

    expect($after->home_recent_form)->toBe($before->home_recent_form)
        ->and($after->turnover_diff_adj)->toBe($before->turnover_diff_adj)
        ->and($after->rebound_margin_adj)->toBe($before->rebound_margin_adj)
        ->and($after->predicted_spread)->toBe($before->predicted_spread)
        ->and($after->predicted_total)->toBe($before->predicted_total);
});

it('omits legacy home court adjustments at a known neutral site', function () {
    $event = SportEvent::factory()->create(['sport' => 'wcbb', 'neutral_site' => true]);
    $game = Game::factory()->create([
        'sport_event_id' => $event->id,
        'neutral_site' => $event->neutral_site,
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
    ]);

    $prediction = (new GeneratePrediction)->execute($game);

    expect((float) $prediction->elo_spread_component)->toBe(
        round((1550 - 1450) / config('wcbb.prediction.elo_to_spread_divisor'), 2)
    )->and((float) $prediction->form_spread_component)->toBe(0.0)
        ->and((float) $prediction->home_away_split_adj)->toBe(0.0);
});

it('removes home court advantage for canonical WCBB neutral-site projections', function () {
    $neutralDefinition = new NeutralAwareBasketballReleaseDefinition(new WcbbCalculationReleaseDefinition);
    $releaseModel = app(CanonicalRulesReleaseRegistrar::class)->register($neutralDefinition, WcbbCalculator::class, NeutralAwareBasketballInputSnapshotBuilder::class, approve: false);
    $release = CalculationReleaseData::fromModel($releaseModel);
    $calculator = new BasketballPolicyCalculator(new WcbbCalculator, 'wcbb');
    $inputs = ['home' => ['elo' => 1500], 'away' => ['elo' => 1500]];
    $calculate = fn (bool $neutralSite) => $calculator->calculate(new EventInputSnapshotData(
        schemaVersion: $release->inputSchemaVersion,
        inputs: [...$inputs, 'event' => ['neutral_site' => $neutralSite]],
        capturedAt: now()->toImmutable(),
    ), $release);

    $homeCourt = $calculate(false);
    $neutral = $calculate(true);

    expect($homeCourt->metadata['home_margin'])->toBeGreaterThan(0)
        ->and($neutral->metadata['home_margin'])->toBe(0.0)
        ->and($neutral->markets[0]->probability)->toBe(0.5)
        ->and($neutral->markets[2]->projectedLine)->toEqual(0.0)
        ->and($neutral->markets[3]->projectedLine)->toBe($homeCourt->markets[3]->projectedLine);
});

it('uses the selected team quote regardless of outcome order or overlapping school names', function (bool $reversed) {
    $this->awayTeam->update(['school' => 'Iowa State', 'mascot' => 'Cyclones']);
    $outcomes = [
        ['name' => 'Iowa State Cyclones', 'point' => 5, 'price' => -115],
        ['name' => 'Iowa Hawkeyes', 'point' => -5, 'price' => -105],
    ];
    $game = Game::factory()->create([
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => ['bookmakers' => [['markets' => [[
            'key' => 'spreads',
            'outcomes' => $reversed ? array_reverse($outcomes) : $outcomes,
        ]]]]],
    ]);

    $prediction = (new GeneratePrediction)->execute($game);
    expect((float) $prediction->vegas_spread)->toBe(-5.0);

    $prediction->predicted_spread = 1;
    $game->setRelation('prediction', $prediction);
    $recommendation = (new CalculateBettingValue)->execute($game, 'wcbb')[0];

    expect($recommendation['pick_side'])->toBe('away')
        ->and($recommendation['line'])->toBe(5.0)
        ->and($recommendation['odds'])->toBe(-115.0);

    unset($outcomes[0]['price']);
    $game->odds_data = ['bookmakers' => [['markets' => [['key' => 'spreads', 'outcomes' => $outcomes]]]]];
    expect((new CalculateBettingValue)->execute($game, 'wcbb'))->toBeNull();
})->with([false, true]);

it('tracks and grades a canonical WCBB spread from frozen quotes and compares only paired pregame forecasts', function (int $homeScore, string $result, float $homeLine, string $side) {
    $this->travelTo(now()->startOfSecond());
    config()->set('wcbb.betting.edge_thresholds.spread', 1.0);
    $start = now()->addHours(2);
    $event = SportEvent::factory()->create([
        'sport' => 'wcbb',
        'season' => 2026,
        'starts_at' => $start,
        'neutral_site' => true,
    ]);
    $game = Game::factory()->create([
        'sport_event_id' => $event->id,
        'neutral_site' => $event->neutral_site,
        'home_team_id' => $this->homeTeam->id,
        'away_team_id' => $this->awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'season' => 2026,
        'game_date' => $start->copy()->utc()->toDateString(),
        'game_time' => $start->copy()->utc()->format('H:i:s'),
    ]);
    $odds = [
        'home_team' => 'Iowa Hawkeyes',
        'away_team' => 'UConn Huskies',
        'bookmakers' => [['key' => 'test', 'markets' => [[
            'key' => 'spreads',
            'outcomes' => [
                ['name' => 'Iowa Hawkeyes', 'point' => $homeLine, 'price' => -105],
                ['name' => 'UConn Huskies', 'point' => -$homeLine, 'price' => -115],
            ],
        ]]]],
    ];
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'wcbb',
        'game_table' => 'wcbb_games',
        'game_id' => $game->id,
        'source' => 'test',
        'captured_at' => now()->subMinute(),
        'commence_time' => $start,
        'payload_hash' => hash('sha256', json_encode($odds)),
        'odds_data' => $odds,
    ]);
    app(MarketQuoteRecorder::class)->record($oddsSnapshot, $odds);

    $legacy = (new GeneratePrediction)->execute($game, dispatchNarratives: false);
    expect(PredictionFeatureSnapshot::query()->sole()->game_start_at->getTimestamp())->toBe($start->getTimestamp())
        ->and(PredictionFeatureSnapshot::query()->sole()->pregame_safe)->toBeTrue();
    app(WcbbCalculationReleaseRegistrar::class)->register();
    $generator = app(GenerateCanonicalPrediction::class);
    $prediction = $generator->execute($game);
    $generator->execute($game);
    $decision = BetDecision::query()->sole();

    $line = $side === 'home' ? $homeLine : -$homeLine;
    $price = $side === 'home' ? -105 : -115;
    expect($decision->prediction_id)->toBe($prediction->id)
        ->and($decision->side)->toBe($side)
        ->and((float) $decision->line)->toBe($line)
        ->and((int) $decision->price)->toBe($price)
        ->and($decision->is_public)->toBeFalse()
        ->and($decision->is_tracking_only)->toBeTrue();

    config()->set('prediction_lifecycle.canonical_reads.wcbb', true);
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('view-wcbb-predictions', 'web'));
    Sanctum::actingAs($user);
    $endpoint = "/api/v2/sports/wcbb/games/{$game->id}/prediction";
    $this->getJson($endpoint)->assertOk()->assertJsonPath('data.value_signal', null);
    $user->givePermissionTo(Permission::findOrCreate('view-prediction-spread', 'web'));
    $this->getJson($endpoint)->assertOk()
        ->assertJsonPath('data.value_signal.has_playable_value', false)
        ->assertJsonPath('data.value_signal.best.side', $side)
        ->assertJsonPath('data.value_signal.best.price', $price)
        ->assertJsonPath('data.value_signal.best.grade', 'Tracking only');
    config()->set('prediction_lifecycle.canonical_reads.wcbb', false);

    $this->travelTo($start->copy()->addHours(3));
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => $homeScore, 'away_score' => 60, 'game_clock' => '0:00']);
    $evaluator = app(EvaluateCanonicalPrediction::class);
    $evaluator->execute($game);
    $evaluator->execute($game);

    expect(BetSettlement::query()->sole()->result_status)->toBe($result);

    expect(fn () => (new GeneratePrediction)->executeHistorical($game))
        ->toThrow(PredictionLifecycleException::class, 'lacks point-in-time inputs');
    expect(PredictionFeatureSnapshot::query()->where('game_id', $game->id)->count())->toBe(1);
    $reconstruction = PredictionFeatureSnapshot::query()->sole()->replicate();
    $reconstruction->fill([
        'snapshot_run_id' => (string) Str::uuid(),
        'outputs' => [...$reconstruction->outputs, 'predicted_spread' => 99],
        'lineage_metadata' => ['run_type' => 'historical_reconstruction'],
    ])->save();
    expect(Artisan::call('wcbb:compare-pregame-predictions', ['--season' => 2026]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['coverage']['paired'])->toBe(1)
        ->and($report['cohorts']['all']['legacy']['spread_mae'])->toEqual(round(abs($homeScore - 60 - (float) $legacy->predicted_spread), 4))
        ->and($report['cohorts']['neutral_site']['canonical']['games'])->toBe(1);
})->with([
    'away cover' => [69, 'win', -10.0, 'away'],
    'away push' => [70, 'push', -10.0, 'away'],
    'away miss' => [71, 'loss', -10.0, 'away'],
    'home cover' => [62, 'win', -1.0, 'home'],
]);
