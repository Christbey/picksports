<?php

use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Models\UserBet;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

function userBetPayload(array $overrides = []): array
{
    return array_merge([
        'bet_amount' => 100,
        'odds' => '-110',
        'bet_type' => 'spread',
        'selection_side' => 'home',
        'selection_label' => 'Home -3.5',
        'line' => -3.5,
    ], $overrides);
}

it('replays a completed v2 user bet write without running it twice', function () {
    $user = User::factory()->create();
    $headers = ['Idempotency-Key' => 'bet-create-01'];

    $first = $this->actingAs($user)->postJson('/api/v2/user-bets', userBetPayload(), $headers);
    $second = $this->actingAs($user)->postJson('/api/v2/user-bets', userBetPayload(), $headers);

    $first->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false')
        ->assertHeader('Idempotency-Key-Expires-At');
    $second->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertHeader('Idempotency-Key-Expires-At');

    expect($second->getContent())->toBe($first->getContent())
        ->and(UserBet::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(ApiIdempotencyKey::query()->count())->toBe(1)
        ->and(ApiIdempotencyKey::query()->value('key_hash'))->toBe(hash('sha256', 'bet-create-01'))
        ->and(ApiIdempotencyKey::query()->value('key_hash'))->not->toBe('bet-create-01');
});

it('rejects reuse of a scoped idempotency key with a different payload', function () {
    $user = User::factory()->create();
    $headers = ['Idempotency-Key' => 'bet-create-conflict'];

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', userBetPayload(), $headers)
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', userBetPayload(['bet_amount' => 250]), $headers)
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(UserBet::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('scopes the same idempotency key to each authenticated principal', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $headers = ['Idempotency-Key' => 'shared-client-key'];

    $this->actingAs($firstUser)
        ->postJson('/api/v2/user-bets', userBetPayload(), $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false');

    $this->actingAs($secondUser)
        ->postJson('/api/v2/user-bets', userBetPayload(), $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false');

    expect(UserBet::query()->count())->toBe(2)
        ->and(ApiIdempotencyKey::query()->distinct()->count('scope_hash'))->toBe(2);
});

it('allows an expired idempotency key to start a new request', function () {
    $user = User::factory()->create();
    $headers = ['Idempotency-Key' => 'expired-client-key'];

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', userBetPayload(), $headers)
        ->assertCreated();

    ApiIdempotencyKey::query()->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', userBetPayload(['bet_amount' => 200]), $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false');

    expect(UserBet::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(ApiIdempotencyKey::query()->count())->toBe(1)
        ->and(ApiIdempotencyKey::query()->first()->expires_at->isFuture())->toBeTrue();
});

it('replays v2 user bet updates against a stable route fingerprint', function () {
    $user = User::factory()->create();
    $bet = UserBet::factory()->create([
        'user_id' => $user->id,
        'result' => 'pending',
    ]);
    $headers = ['Idempotency-Key' => 'bet-update-01'];

    $first = $this->actingAs($user)->putJson("/api/v2/user-bets/{$bet->id}", ['result' => 'won'], $headers);
    $second = $this->actingAs($user)->putJson("/api/v2/user-bets/{$bet->id}", ['result' => 'won'], $headers);

    $first->assertOk()->assertHeader('Idempotency-Replayed', 'false');
    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent())
        ->and($bet->fresh()->result)->toBe('won');
});

it('replays an idempotent v2 delete before implicit model binding can return not found', function () {
    $user = User::factory()->create();
    $bet = UserBet::factory()->create(['user_id' => $user->id]);
    $headers = ['Idempotency-Key' => 'bet-delete-01'];

    $this->actingAs($user)->deleteJson("/api/v2/user-bets/{$bet->id}", [], $headers)
        ->assertNoContent()
        ->assertHeader('Idempotency-Replayed', 'false');
    $this->actingAs($user)->deleteJson("/api/v2/user-bets/{$bet->id}", [], $headers)
        ->assertNoContent()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(UserBet::query()->whereKey($bet->id)->exists())->toBeFalse();
});

it('uses the named v2 write rate limiter and preserves its response headers', function () {
    config()->set('api.v2.rate_limits.writes_per_minute', 1);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', userBetPayload(), ['Idempotency-Key' => 'rate-limit-01'])
        ->assertCreated()
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertHeader('X-RateLimit-Remaining', '0');

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', userBetPayload(), ['Idempotency-Key' => 'rate-limit-02'])
        ->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'rate_limit_exceeded');
});

it('schedules expired idempotency key pruning once daily on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => $event->description === 'Maintenance: Prune API Idempotency Keys'
    );

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->toContain('model:prune --model=App\\Models\\ApiIdempotencyKey')
        ->and($event?->expression)->toBe('30 3 * * *')
        ->and($event?->onOneServer)->toBeTrue()
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->runInBackground)->toBeTrue();
});

it('protects authenticated v2 data mutations with write throttling and idempotency', function () {
    $protectedMutations = [
        'v2.user-bets.store',
        'v2.user-bets.update',
        'v2.user-bets.destroy',
        'v2.cbb-brackets.store',
        'v2.cbb-brackets.current.upsert',
        'v2.cbb-brackets.update',
        'v2.cbb-brackets.destroy',
        'v2.groups.store',
        'v2.groups.update',
        'v2.alert-preferences.store',
        'v2.alert-preferences.update',
        'v2.auth.device-sessions.push-registrations.store',
        'v2.auth.device-sessions.push-registrations.destroy',
        'v2.auth.device-sessions.destroy',
    ];

    foreach ($protectedMutations as $routeName) {
        $middleware = collect(Route::getRoutes()->getByName($routeName)?->gatherMiddleware());

        expect($middleware, $routeName)
            ->toContain('throttle:api-v2-writes', 'v2.idempotent');
    }
});
