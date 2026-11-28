<?php

use App\Models\User;
use App\Models\UserBet;

it('returns resource metadata and only owned bets across numeric history pages', function () {
    $user = User::factory()->create();
    foreach (range(1, 21) as $index) {
        UserBet::query()->create(['user_id' => $user->id, 'bet_amount' => 1, 'odds' => '-110', 'bet_type' => 'moneyline', 'selection_label' => 'Owned '.$index, 'placed_at' => now()->subMinutes($index)]);
    }
    UserBet::query()->create(['user_id' => User::factory()->create()->id, 'bet_amount' => 900, 'odds' => '-110', 'bet_type' => 'moneyline', 'selection_label' => 'Private other account', 'placed_at' => now()]);
    $first = $this->actingAs($user)->getJson('/api/v2/user-bets?page=1')->assertOk()
        ->assertJsonCount(20, 'bets.data')->assertJsonPath('bets.meta.current_page', 1)->assertJsonPath('bets.meta.last_page', 2)->assertJsonPath('bets.meta.total', 21);
    $second = $this->getJson('/api/v2/user-bets?page=2')->assertOk()->assertJsonCount(1, 'bets.data')->assertJsonPath('bets.meta.current_page', 2)->assertJsonPath('bets.data.0.selection_label', 'Owned 21');
    expect(array_intersect(array_column($first->json('bets.data'), 'id'), array_column($second->json('bets.data'), 'id')))->toBeEmpty();
    expect($second->json('statistics.total_bets'))->toBe(21);
});
