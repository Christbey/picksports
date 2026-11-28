<?php

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\WebPush\PushDnsResolver;
use App\Services\WebPush\PushHttpTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    $transport = Mockery::mock(PushHttpTransport::class);
    $transport->shouldNotReceive('post');
    app()->instance(PushHttpTransport::class, $transport);
    $dns = Mockery::mock(PushDnsResolver::class);
    $dns->shouldNotReceive('addresses');
    app()->instance(PushDnsResolver::class, $dns);
});

afterEach(function () {
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

function webPushPersistencePayload(): array
{
    return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/SyntheticPersistenceToken',
        'contentEncoding' => 'aes128gcm',
        'keys' => ['p256dh' => 'synthetic-original-public', 'auth' => 'synthetic-original-auth']];
}

function webPushPersistenceExisting(User $user): WebPushSubscription
{
    $payload = webPushPersistencePayload();

    return WebPushSubscription::create(['user_id' => $user->id, 'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'], 'auth_token' => $payload['keys']['auth'],
        'content_encoding' => $payload['contentEncoding'], 'user_agent' => 'synthetic-original-agent',
        'expired_at' => now()->subDay()]);
}

function webPushPersistenceRows(): array
{
    return DB::table('web_push_subscriptions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

it('refuses a cancelled final push save instead of acknowledging an update or possession transfer', function (bool $transfer) {
    $owner = User::factory()->create();
    $requester = $transfer ? User::factory()->create() : $owner;
    $subscription = webPushPersistenceExisting($owner);
    $payload = webPushPersistencePayload();
    if (! $transfer) {
        $payload['keys'] = ['p256dh' => 'synthetic-requested-public', 'auth' => 'synthetic-requested-auth'];
    }
    $before = webPushPersistenceRows();
    $original = WebPushSubscription::getEventDispatcher();
    WebPushSubscription::setEventDispatcher(clone $original);
    $seen = 0;
    WebPushSubscription::saving(function () use (&$seen): bool {
        $seen++;

        return false;
    });
    try {
        $response = $this->actingAs($requester)->withHeader('User-Agent', 'synthetic-requested-agent')
            ->postJson(route('web-push.subscriptions.store'), $payload);
    } finally {
        WebPushSubscription::setEventDispatcher($original);
    }
    $after = webPushPersistenceRows();
    $diagnostic = json_encode(['case' => $transfer ? 'cancelled_transfer' : 'cancelled_update',
        'http_status' => $response->status(), 'response' => $response->json(),
        'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR);
    expect($seen)->toBe(1);
    $this->assertFalse($response->isSuccessful(), $diagnostic);
    $response->assertUnprocessable();
    expect($after)->toBe($before)->and($subscription->fresh()->user_id)->toBe($owner->id);
})->with(['same owner update' => false, 'matching possession transfer' => true]);

it('refuses rewritten final push registration fields without persisting a false success', function (string $field) {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    webPushPersistenceExisting($owner);
    $payload = webPushPersistencePayload();
    $payload['keys'] = ['p256dh' => 'synthetic-requested-public', 'auth' => 'synthetic-requested-auth'];
    $before = webPushPersistenceRows();
    $original = WebPushSubscription::getEventDispatcher();
    WebPushSubscription::setEventDispatcher(clone $original);
    $seen = 0;
    WebPushSubscription::saving(function (WebPushSubscription $subscription) use ($field, $foreign, &$seen): void {
        $seen++;
        $subscription->{$field} = match ($field) {
            'user_id' => $foreign->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/SyntheticOtherToken',
            'expired_at' => '2027-01-01 12:00:00',
            default => 'synthetic-observer-rewritten',
        };
    });
    try {
        $response = $this->actingAs($owner)->postJson(route('web-push.subscriptions.store'), $payload);
    } finally {
        WebPushSubscription::setEventDispatcher($original);
    }
    $after = webPushPersistenceRows();
    $diagnostic = json_encode(['case' => 'rewrite_'.$field, 'http_status' => $response->status(),
        'response' => $response->json(), 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR);
    expect($seen)->toBe(1);
    $this->assertFalse($response->isSuccessful(), $diagnostic);
    $response->assertUnprocessable();
    expect($after)->toBe($before);
})->with(['public_key', 'auth_token', 'content_encoding', 'user_id', 'endpoint', 'user_agent', 'expired_at']);

it('preserves current authenticated push registration and same owner updates', function () {
    $owner = User::factory()->create();
    $payload = webPushPersistencePayload();
    $this->actingAs($owner)->withHeader('User-Agent', 'synthetic-first-agent')
        ->postJson(route('web-push.subscriptions.store'), $payload)->assertOk()->assertJsonPath('ok', true);
    $subscription = WebPushSubscription::sole();
    $payload['keys'] = ['p256dh' => 'synthetic-next-public', 'auth' => 'synthetic-next-auth'];
    $this->withHeader('User-Agent', 'synthetic-next-agent')->postJson(route('web-push.subscriptions.store'), $payload)
        ->assertOk()->assertJsonPath('id', $subscription->id);
    $current = $subscription->fresh();
    expect(WebPushSubscription::count())->toBe(1)->and($current->user_id)->toBe($owner->id)
        ->and($current->endpoint)->toBe($payload['endpoint'])->and($current->public_key)->toBe($payload['keys']['p256dh'])
        ->and($current->auth_token)->toBe($payload['keys']['auth'])->and($current->content_encoding)->toBe('aes128gcm')
        ->and($current->user_agent)->toBe('synthetic-next-agent')->and($current->expired_at)->toBeNull();
});

it('preserves legitimate browser possession transfer without replacing its row identity', function () {
    $owner = User::factory()->create();
    $requester = User::factory()->create();
    $subscription = webPushPersistenceExisting($owner);
    $this->actingAs($requester)->postJson(route('web-push.subscriptions.store'), webPushPersistencePayload())
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('id', $subscription->id);
    expect(WebPushSubscription::count())->toBe(1)->and($subscription->fresh()->user_id)->toBe($requester->id)
        ->and($subscription->fresh()->expired_at)->toBeNull();
});

it('retains original possession requirements and raw owner state on refused transfer', function (string $part) {
    $owner = User::factory()->create();
    $requester = User::factory()->create();
    webPushPersistenceExisting($owner);
    $before = webPushPersistenceRows();
    $payload = webPushPersistencePayload();
    if ($part === 'encoding') {
        $payload['contentEncoding'] = 'other';
    } else {
        $payload['keys'][$part] = 'synthetic-not-original';
    }
    $this->actingAs($requester)->postJson(route('web-push.subscriptions.store'), $payload)->assertConflict();
    expect(webPushPersistenceRows())->toBe($before);
})->with(['p256dh', 'auth', 'encoding']);

it('refuses a saved hook rewriting the persisted push row behind unchanged model memory', function () {
    $owner = User::factory()->create();
    webPushPersistenceExisting($owner);
    $payload = webPushPersistencePayload();
    $payload['keys'] = ['p256dh' => 'synthetic-requested-public', 'auth' => 'synthetic-requested-auth'];
    $before = webPushPersistenceRows();
    $original = WebPushSubscription::getEventDispatcher();
    WebPushSubscription::setEventDispatcher(clone $original);
    $seen = 0;
    WebPushSubscription::saved(function (WebPushSubscription $subscription) use (&$seen): void {
        $seen++;
        $subscription->getConnection()->table('web_push_subscriptions')->where('id', $subscription->id)
            ->update(['auth_token' => 'synthetic-post-save-rewritten']);
    });
    try {
        $response = $this->actingAs($owner)->postJson(route('web-push.subscriptions.store'), $payload);
    } finally {
        WebPushSubscription::setEventDispatcher($original);
    }
    $after = webPushPersistenceRows();
    $diagnostic = json_encode(['case' => 'saved_row_rewrite', 'http_status' => $response->status(),
        'response' => $response->json(), 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR);
    expect($seen)->toBe(1);
    $this->assertFalse($response->isSuccessful(), $diagnostic);
    $response->assertUnprocessable();
    expect($after)->toBe($before);
});

it('refuses a null user agent rewrite of an explicitly empty registration header', function () {
    $owner = User::factory()->create();
    webPushPersistenceExisting($owner);
    $before = webPushPersistenceRows();
    $original = WebPushSubscription::getEventDispatcher();
    WebPushSubscription::setEventDispatcher(clone $original);
    $incoming = [];
    WebPushSubscription::saving(function (WebPushSubscription $subscription) use (&$incoming): void {
        $incoming[] = $subscription->user_agent;
    });
    WebPushSubscription::saved(function (WebPushSubscription $subscription): void {
        $subscription->getConnection()->table('web_push_subscriptions')->where('id', $subscription->id)
            ->update(['user_agent' => null]);
    });
    try {
        $response = $this->actingAs($owner)->withHeader('User-Agent', '')
            ->postJson(route('web-push.subscriptions.store'), webPushPersistencePayload());
    } finally {
        WebPushSubscription::setEventDispatcher($original);
    }
    $after = webPushPersistenceRows();
    $diagnostic = json_encode(['case' => 'empty_header_null_rewrite', 'desired_agent_seen_before_callback' => $incoming,
        'http_status' => $response->status(), 'response' => $response->json(), 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR);
    expect($incoming)->toBe(['']);
    $this->assertFalse($response->isSuccessful(), $diagnostic);
    $response->assertUnprocessable();
    expect($after)->toBe($before);
});

it('preserves an explicitly empty registration header when persisted unchanged', function () {
    $owner = User::factory()->create();
    $subscription = webPushPersistenceExisting($owner);
    $this->actingAs($owner)->withHeader('User-Agent', '')
        ->postJson(route('web-push.subscriptions.store'), webPushPersistencePayload())
        ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('id', $subscription->id);
    expect($subscription->fresh()->getRawOriginal('user_agent'))->toBe('')
        ->and($subscription->fresh()->user_id)->toBe($owner->id);
});
