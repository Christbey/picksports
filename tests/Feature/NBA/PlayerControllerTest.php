<?php

use App\Models\NBA\Player;
use App\Models\NBA\Team;
use App\Models\User;
use Spatie\Permission\Models\Permission;

test('guests are redirected to login when visiting player page', function () {
    $team = Team::factory()->create();
    $player = Player::factory()->for($team)->create();

    $this->get("/nba/players/{$player->id}")
        ->assertRedirect(route('login'));
});

test('authenticated users can visit a player page', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('view-nba-predictions', 'web');
    $user->givePermissionTo('view-nba-predictions');
    $team = Team::factory()->create();
    $player = Player::factory()->for($team)->create();

    $this->actingAs($user)
        ->get("/nba/players/{$player->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('NBA/Player')
            ->where('playerId', $player->id)
        );
});
