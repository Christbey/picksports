<?php

use App\Models\User;

it('redirects an authenticated account without a subscription to plans', function () {
    config()->set('founding_users.enabled', false);

    $this->actingAs(User::factory()->create())
        ->get(route('subscription.manage'))
        ->assertRedirect(route('subscription.plans'));
});
