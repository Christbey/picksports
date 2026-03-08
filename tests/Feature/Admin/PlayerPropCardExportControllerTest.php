<?php

use App\Models\User;

test('admin can view player prop export page', function () {
    $this->withoutVite();

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('settings.prop-exports'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/PlayerPropExports')
        ->has('recommendations')
        ->has('dates')
        ->has('games')
        ->has('markets')
        ->where('sport', 'NBA')
    );
});
