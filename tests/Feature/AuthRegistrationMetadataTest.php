<?php

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    config()->set('app.name', 'PickSports');
    config()->set('site_assets.disk', 'public');
    config()->set('site_assets.directory', 'site-assets');
    config()->set('site_assets.mirror', true);
});

test('register invite link renders group-specific share metadata', function () {
    $owner = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $owner->id,
        'name' => 'Office Pool',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);

    $invitation = GroupInvitation::query()->create([
        'group_id' => $group->id,
        'invited_by' => $owner->id,
        'email' => 'invitee@example.com',
        'expires_at' => now()->addDays(7),
    ]);

    $this->get("/register?invite={$invitation->token}")
        ->assertOk()
        ->assertSee('Join Office Pool on PickSports', false)
        ->assertSee('Accept your invitation to join Office Pool, create your account, and complete your March Madness bracket on PickSports.', false)
        ->assertSee('/storage/site-assets/branding/picksports-share.png?v=20260316-3', false);
});

test('register join link renders group-specific share metadata', function () {
    $owner = User::factory()->create();

    $group = Group::query()->create([
        'owner_id' => $owner->id,
        'name' => 'Friends Bracket',
        'type' => 'bracket_pool',
        'sport' => 'cbb',
        'season' => 2026,
    ]);

    $joinLink = GroupJoinLink::query()->create([
        'group_id' => $group->id,
        'created_by' => $owner->id,
    ]);

    $this->get("/register?join={$joinLink->token}")
        ->assertOk()
        ->assertSee('Join Friends Bracket on PickSports', false)
        ->assertSee('Use this shared link to join Friends Bracket, create your account, and fill out your March Madness bracket on PickSports.', false)
        ->assertSee('/storage/site-assets/branding/picksports-share.png?v=20260316-3', false);
});
