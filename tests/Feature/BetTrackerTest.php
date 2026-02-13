<?php

use App\Models\User;
use App\Models\UserBet;

test('authenticated users can view their bets via API', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/api/v1/user-bets');

    $response->assertOk();
    $response->assertJsonStructure([
        'bets' => [
            'data',
        ],
        'statistics' => [
            'total_bets',
            'wins',
            'losses',
            'win_rate',
            'total_wagered',
            'total_profit',
            'roi',
        ],
    ]);
});

test('guests cannot access bet tracker API', function () {
    $response = $this->get('/api/v1/user-bets');

    $response->assertRedirect();
});

test('users can log a new bet via API', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/api/v1/user-bets', [
        'prediction_id' => 1,
        'prediction_type' => 'App\Models\NBA\Prediction',
        'bet_amount' => 100.00,
        'odds' => '-110',
        'bet_type' => 'spread',
        'notes' => 'Test bet',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => [
            'id',
            'bet_amount',
            'odds',
            'bet_type',
            'notes',
        ],
    ]);

    $this->assertDatabaseHas('user_bets', [
        'user_id' => $user->id,
        'bet_amount' => 100.00,
        'odds' => '-110',
        'bet_type' => 'spread',
        'notes' => 'Test bet',
    ]);
});

test('users can update bet results via API', function () {
    $user = User::factory()->create();
    $bet = UserBet::factory()->create([
        'user_id' => $user->id,
        'result' => 'pending',
    ]);

    $response = $this->actingAs($user)->put("/api/v1/user-bets/{$bet->id}", [
        'result' => 'won',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('user_bets', [
        'id' => $bet->id,
        'result' => 'won',
    ]);
});

test('users cannot update other users bets via API', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $bet = UserBet::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->put("/api/v1/user-bets/{$bet->id}", [
        'result' => 'won',
    ]);

    $response->assertForbidden();
});

test('users can delete their bets via API', function () {
    $user = User::factory()->create();
    $bet = UserBet::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->delete("/api/v1/user-bets/{$bet->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('user_bets', [
        'id' => $bet->id,
    ]);
});

test('users cannot delete other users bets via API', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $bet = UserBet::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->delete("/api/v1/user-bets/{$bet->id}");

    $response->assertForbidden();
});

test('statistics are calculated correctly via API', function () {
    $user = User::factory()->create();

    // Create test bets
    UserBet::factory()->create([
        'user_id' => $user->id,
        'bet_amount' => 100,
        'result' => 'won',
        'profit_loss' => 90.91,
    ]);

    UserBet::factory()->create([
        'user_id' => $user->id,
        'bet_amount' => 100,
        'result' => 'lost',
        'profit_loss' => -100,
    ]);

    UserBet::factory()->create([
        'user_id' => $user->id,
        'bet_amount' => 100,
        'result' => 'pending',
        'profit_loss' => null,
    ]);

    $response = $this->actingAs($user)->get('/api/v1/user-bets');

    $response->assertOk();
    $response->assertJson([
        'statistics' => [
            'total_bets' => 3,
            'wins' => 1,
            'losses' => 1,
            'win_rate' => 50,
            'total_wagered' => 300,
        ],
    ]);
});

test('users can export their bets to csv', function () {
    $user = User::factory()->create();

    UserBet::factory()->create([
        'user_id' => $user->id,
        'bet_amount' => 100,
        'odds' => '-110',
    ]);

    $response = $this->actingAs($user)->get('/api/v1/user-bets/export');

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $response->assertHeader('content-disposition');
});

test('bet result updates set settled_at timestamp', function () {
    $user = User::factory()->create();
    $bet = UserBet::factory()->create([
        'user_id' => $user->id,
        'result' => 'pending',
        'settled_at' => null,
    ]);

    $this->actingAs($user)->put("/api/v1/user-bets/{$bet->id}", [
        'result' => 'won',
    ]);

    $bet->refresh();

    expect($bet->settled_at)->not->toBeNull();
    expect($bet->result)->toBe('won');
});
