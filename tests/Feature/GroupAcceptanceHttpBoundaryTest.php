<?php

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->withoutVite();
});

it('does not join a signed-in account from a read request', function (string $method, string $kind) {
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Synthetic membership boundary', 'type' => 'bracket_pool', 'sport' => 'cbb', 'season' => 2026]);
    $link = $kind === 'invite'
        ? GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email, 'expires_at' => now()->addDay()])
        : GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $owner->id]);
    $url = ($kind === 'invite' ? '/group-invitations/' : '/groups/join/').$link->token;
    $this->actingAs($user)->call($method, $url)->assertOk();
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
    if ($kind === 'invite') {
        expect($link->fresh()->accepted_at)->toBeNull();
    }
})->with([['GET', 'invite'], ['HEAD', 'invite'], ['GET', 'join'], ['HEAD', 'join']]);

function groupAcceptanceFixture(string $kind): array
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => '<script>unsafe group</script>', 'type' => 'bracket_pool', 'sport' => 'cbb', 'season' => 2026]);
    $link = $kind === 'invite'
        ? GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email, 'expires_at' => now()->addDay()])
        : GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $owner->id]);

    return [$user, $group, $link, ($kind === 'invite' ? '/group-invitations/' : '/groups/join/').$link->token];
}

it('shows an escaped confirmation and accepts only the explicit signed-in post', function (string $kind) {
    [$user, $group, $link, $url] = groupAcceptanceFixture($kind);
    $this->actingAs($user)->get($url)->assertOk()->assertSee('name="_token"', false)
        ->assertDontSee('<script>unsafe group</script>', false)->assertSee('&lt;script&gt;unsafe group&lt;/script&gt;', false)
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->post($url)->assertRedirect('/march-madness-bracket');
    $this->assertDatabaseHas('group_users', ['group_id' => $group->id, 'user_id' => $user->id, 'role' => 'member']);
    if ($kind === 'invite') {
        expect($link->fresh()->accepted_by)->toBe($user->id);
    } else {
        $this->post($url)->assertRedirect('/march-madness-bracket');
        expect($group->users()->where('users.id', $user->id)->count())->toBe(1);
    }
})->with(['invite', 'join']);

it('rechecks expired or revoked state after the landing', function (string $kind) {
    [$user, $group, $link, $url] = groupAcceptanceFixture($kind);
    $this->actingAs($user)->get($url)->assertOk();
    $link->forceFill($kind === 'invite' ? ['expires_at' => now()->subMinute()] : ['revoked_at' => now()])->save();
    $this->post($url)->assertNotFound();
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
})->with(['invite', 'join']);

it('does not accept an invitation for another email', function () {
    [$user, $group, $link, $url] = groupAcceptanceFixture('invite');
    $other = User::factory()->create();
    $this->actingAs($other)->post($url)->assertRedirect('/march-madness-bracket')->assertSessionHas('error');
    expect($link->fresh()->accepted_at)->toBeNull();
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $other->id]);
});

class GroupAcceptanceCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

it('requires a real CSRF token on the acceptance route', function (string $kind) {
    [$user, $group, $link, $url] = groupAcceptanceFixture($kind);
    $this->withMiddleware(ValidateCsrfToken::class);
    $this->app->bind(ValidateCsrfToken::class, GroupAcceptanceCsrfMiddleware::class);
    $this->actingAs($user)->post($url)->assertStatus(419);
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
    $this->withSession(['_token' => 'synthetic-group-confirmation'])->post($url, ['_token' => 'synthetic-group-confirmation'])
        ->assertRedirect('/march-madness-bracket');
    $this->assertDatabaseHas('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
})->with(['invite', 'join']);

it('does not accept an unauthenticated post', function (string $kind) {
    [$user, $group, $link, $url] = groupAcceptanceFixture($kind);
    $this->post($url)->assertRedirect('/login');
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
})->with(['invite', 'join']);
