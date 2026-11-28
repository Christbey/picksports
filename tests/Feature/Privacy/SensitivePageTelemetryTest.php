<?php

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Support\Privacy\SensitiveDocument;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->withoutVite();
});

it('does not load third-party tags or ads on signed-in credential and invitation entry documents', function (string $path) {
    $response = $this->get($path);
    $response->assertOk();
    expect($response->getContent())->not->toContain('googletagmanager.com', 'pagead2.googlesyndication.com');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['/reset-password/synthetic-private-token?email=synthetic%40example.test', '/register?invite=synthetic-private-invite', '/login']);

it('retains the public document tag loader for ordinary public pages', function () {
    $response = $this->get('/privacy')->assertOk();
    expect($response->getContent())->toContain('googletagmanager.com');
});

it('uses a full-document handoff before returning sensitive Inertia page data', function () {
    $this->get('/reset-password/synthetic-private-token?email=synthetic%40example.test', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/reset-password/synthetic-private-token').'?email=synthetic%40example.test')
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

it('keeps the guest invitation session and redirect while applying private document headers', function () {
    $owner = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Synthetic private group', 'type' => 'bracket', 'sport' => 'cbb', 'season' => 2026]);
    $invite = GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => 'invited@example.test', 'expires_at' => now()->addDay()]);
    $this->get('/group-invitations/'.$invite->token)
        ->assertRedirect('/register?invite='.$invite->token)
        ->assertSessionHas('group_invitation.token', $invite->token)
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/register?invite='.$invite->token)->assertOk()->assertDontSee('googletagmanager.com');
});

it('keeps authenticated settings credential documents private without losing their session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/settings/profile')
        ->assertOk()
        ->assertDontSee('googletagmanager.com')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertAuthenticatedAs($user);
});

it('classifies actual recovery, invitation, credential management and owned payment routes', function () {
    $paths = SensitiveDocument::paths();
    foreach (['/reset-password/{token}', '/group-invitations/{token}', '/groups/join/{token}', '/settings/profile', '/settings/two-factor', '/settings/admin', '/subscription/operations/{intent}'] as $path) {
        expect($paths)->toContain($path);
    }
    $payment = app('router')->getRoutes()->getByName('cashier.payment');
    expect($payment)->not->toBeNull();
    expect($paths)->toContain('/'.$payment->uri());
});
