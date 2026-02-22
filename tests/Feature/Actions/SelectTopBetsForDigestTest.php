<?php

use App\Actions\Alerts\SelectTopBetsForDigest;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\SubscriptionTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('alerts', 'digest');

beforeEach(function () {
    // Create subscription tiers with different bet limits
    SubscriptionTier::factory()->create([
        'slug' => 'free',
        'name' => 'Free',
        'is_default' => true,
        'features' => ['email_alerts' => true],
    ]);

    SubscriptionTier::factory()->create([
        'slug' => 'premium',
        'name' => 'Premium',
        'stripe_price_id_monthly' => 'price_premium_monthly',
        'features' => ['email_alerts' => true],
    ]);

    $this->action = app(SelectTopBetsForDigest::class);
});

it('respects tier-based bet limits', function () {
    // Create free user (3 bets max)
    $freeUser = User::factory()->create();

    // Create games with predictions and odds
    $games = [];
    for ($i = 0; $i < 10; $i++) {
        $home = Team::factory()->create();
        $away = Team::factory()->create();

        $game = Game::factory()->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'game_date' => now()->addDay(),
            'status' => 'STATUS_SCHEDULED',
            'odds_data' => [
                'home_team' => $home->school,
                'away_team' => $away->school,
                'bookmakers' => [
                    [
                        'markets' => [
                            [
                                'key' => 'spreads',
                                'outcomes' => [
                                    ['name' => $home->school, 'point' => -5.5, 'price' => -110],
                                    ['name' => $away->school, 'point' => 5.5, 'price' => -110],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $game->prediction()->create([
            'season' => 2026,
            'home_elo' => 1500,
            'away_elo' => 1500,
            'predicted_spread' => -10.0, // Big edge
            'predicted_total' => 150.0,
            'win_probability' => 0.75,
            'confidence_score' => 80.0,
        ]);

        $games[] = $game;
    }

    $date = Carbon::parse($games[0]->game_date);
    $topBets = $this->action->execute($freeUser, 'cbb', $date);

    // Free tier should get max 3 bets
    expect($topBets)->toHaveCount(3);
});

it('returns empty collection when no games scheduled', function () {
    $user = User::factory()->create();

    $topBets = $this->action->execute($user, 'cbb', now()->addDays(100));

    expect($topBets)->toBeEmpty();
});

it('filters games by date', function () {
    $user = User::factory()->create();
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    // Game tomorrow
    $gameTomorrow = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
    ]);

    // Game in 2 days
    $gameNextDay = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => now()->addDays(2),
        'status' => 'STATUS_SCHEDULED',
    ]);

    // Request digest for tomorrow only
    $topBets = $this->action->execute($user, 'cbb', now()->addDay());

    // Should only include games from tomorrow
    expect($topBets->pluck('game')->pluck('id')->toArray())
        ->not->toContain($gameNextDay->id);
});
