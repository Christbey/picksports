<?php

use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\MLB\Team as MlbTeam;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard exposes evaluated mlb passes and tracking candidates', function () {
    Carbon::setTestNow('2026-08-10 12:00:00');

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    config()->set('mlb.signals.bet_filter.promotions_validated', false);

    $pass = dashboardMlbPrediction(
        gameTime: '18:10:00',
        winProbability: 0.57,
        homePrice: -194,
        awayPrice: 165,
    );
    $tracking = dashboardMlbPrediction(
        gameTime: '19:10:00',
        winProbability: 0.60,
        homePrice: -105,
        awayPrice: -105,
    );

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('sports.0.name', 'MLB')
            ->where('sports.0.predictions.0.id', $pass->id)
            ->where('sports.0.predictions.0.game_time', '2026-08-10T18:10:00')
            ->where('sports.0.predictions.0.recommendation.candidate.recommendation_type', 'no_play')
            ->where('sports.0.predictions.0.recommendation.candidate.no_bet_reason', 'no_moneyline_market_value')
            ->where('sports.0.predictions.1.id', $tracking->id)
            ->where('sports.0.predictions.1.recommendation.public.recommendation_type', 'no_play')
            ->where('sports.0.predictions.1.recommendation.candidate.recommendation_type', 'bet')
            ->where('sports.0.predictions.1.recommendation.promotion.status', 'blocked')
            ->where('sports.0.predictions.1.recommendation.promotion.block_reasons.0', 'recommendation_calibration_unvalidated'));

    Carbon::setTestNow();
});

test('the dashboard exposes live mlb game state and model movement', function () {
    Carbon::setTestNow('2026-08-10 19:30:00');

    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);

    $prediction = dashboardMlbPrediction(
        gameTime: '19:10:00',
        winProbability: 0.54,
        homePrice: -105,
        awayPrice: -105,
    );
    $prediction->game()->update([
        'status' => config('mlb.statuses.in_progress'),
        'away_score' => 3,
        'home_score' => 4,
        'inning' => 6,
        'inning_half' => 'Bottom 6th',
        'balls' => 2,
        'strikes' => 1,
        'outs' => 2,
    ]);
    $prediction->update([
        'live_win_probability' => 0.68,
        'live_predicted_spread' => 1.4,
        'live_predicted_total' => 9.6,
        'live_outs_remaining' => 10,
        'live_updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('sports.0.name', 'MLB')
            ->where('sports.0.predictions.0.is_live', true)
            ->where('sports.0.predictions.0.inning', 6)
            ->where('sports.0.predictions.0.inning_state', 'Bottom 6th')
            ->where('sports.0.predictions.0.balls', 2)
            ->where('sports.0.predictions.0.strikes', 1)
            ->where('sports.0.predictions.0.outs', 2)
            ->where('sports.0.predictions.0.live_win_probability', 0.68)
            ->where('sports.0.predictions.0.live_predicted_total', 9.6));

    Carbon::setTestNow();
});

function dashboardMlbPrediction(
    string $gameTime,
    float $winProbability,
    int $homePrice,
    int $awayPrice,
): MlbPrediction {
    $awayTeam = MlbTeam::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
        'abbreviation' => 'A'.substr($gameTime, 0, 2),
    ]);
    $homeTeam = MlbTeam::factory()->create([
        'location' => 'Kansas City',
        'name' => 'Royals',
        'abbreviation' => 'H'.substr($gameTime, 0, 2),
    ]);
    $game = MlbGame::factory()->regularSeason()->create([
        'season' => 2026,
        'game_date' => '2026-08-10',
        'game_time' => $gameTime,
        'status' => config('mlb.statuses.scheduled'),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'STL @ KC',
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'markets' => [[
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'St. Louis Cardinals', 'price' => $awayPrice],
                        ['name' => 'Kansas City Royals', 'price' => $homePrice],
                    ],
                ]],
            ]],
        ],
        'odds_updated_at' => now(),
    ]);
    DB::table('mlb_games')->where('id', $game->id)->update([
        'game_date' => '2026-08-10',
    ]);

    return MlbPrediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1510,
        'away_pitcher_elo' => 1490,
        'home_combined_elo' => 1510,
        'away_combined_elo' => 1490,
        'predicted_spread' => 1.6,
        'predicted_total' => 8.2,
        'win_probability' => $winProbability,
        'confidence_score' => 60,
        'model_version' => 'dashboard-signal-test',
        'feature_version' => 'dashboard-signal-test',
        'blend_version' => 'dashboard-signal-test',
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_source' => 'probable_starter',
                'away_source' => 'probable_starter',
                'home_confidence' => 1.0,
                'away_confidence' => 1.0,
            ],
        ],
    ]);
}
