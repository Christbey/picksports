<?php

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\WebPush\PushDnsResolver;
use App\Services\WebPush\PushEndpointPolicy;
use App\Services\WebPush\PushHttpTransport;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Log;

function syntheticPushPolicy(array $addresses): array
{
    $resolver = new class($addresses) extends PushDnsResolver
    {
        public int $calls = 0;

        public function __construct(public array $answers) {}

        public function addresses(string $host): array
        {
            $this->calls++;

            return $this->answers;
        }
    };

    return [new PushEndpointPolicy($resolver), $resolver];
}

function syntheticPushTransport(PushEndpointPolicy $policy): PushHttpTransport
{
    return new class($policy) extends PushHttpTransport
    {
        public array $wireCalls = [];

        public int $status = 201;

        public string $response = '';

        protected function execute(string $endpoint, array $options): int
        {
            $this->wireCalls[] = ['endpoint' => $endpoint, 'options' => $options];
            $options[CURLOPT_WRITEFUNCTION](null, $this->response);

            return $this->status;
        }
    };
}

function pushRegistrationPayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/opaqueCaseSensitiveToken'): array
{
    return ['endpoint' => $endpoint, 'contentEncoding' => 'aes128gcm',
        'keys' => ['p256dh' => 'synthetic-browser-public-key', 'auth' => 'synthetic-browser-auth-secret']];
}

it('rejects unsafe registration endpoints before storage or any DNS resolution', function (string $endpoint) {
    [$policy, $resolver] = syntheticPushPolicy(['8.8.8.8']);
    $this->app->instance(PushEndpointPolicy::class, $policy);
    $this->actingAs(User::factory()->create())
        ->postJson(route('web-push.subscriptions.store'), pushRegistrationPayload($endpoint))
        ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    expect(WebPushSubscription::query()->count())->toBe(0)->and($resolver->calls)->toBe(0);
})->with([
    'http://127.0.0.1/private', 'https://127.0.0.1/private', 'https://[::1]/private',
    'https://169.254.169.254/private', 'ftp://fcm.googleapis.com/file',
    'https://user:secret@fcm.googleapis.com/sub', 'https://fcm.googleapis.com:443/sub',
    'https://fcm.googleapis.com:8443/sub', 'https://fcm.googleapis.com/sub#fragment',
    'https://fcm.googleapis.com.attacker.invalid/sub', 'https://attacker.invalid/sub',
    'https://android.googleapis.com/unrelated', 'https://notify.windows.com.attacker.invalid/sub',
    'https://fcm.googleapis.com/fcm/send/'.str_repeat('x', 256),
]);

it('admits the explicit browser provider endpoints without changing opaque token bytes', function (string $endpoint) {
    [$policy, $resolver] = syntheticPushPolicy([]);
    $this->app->instance(PushEndpointPolicy::class, $policy);
    $this->actingAs(User::factory()->create())
        ->postJson(route('web-push.subscriptions.store'), pushRegistrationPayload($endpoint))->assertOk();
    expect(WebPushSubscription::query()->sole()->endpoint)->toBe($endpoint)
        ->and($resolver->calls)->toBe(0);
})->with([
    'https://fcm.googleapis.com/fcm/send/OpaqueCaseSensitiveToken',
    'https://updates.push.services.mozilla.com/wpush/v2/OpaqueCaseSensitiveToken',
    'https://web.push.apple.com/OpaqueCaseSensitiveToken',
    'https://wns2-test.notify.windows.com/w/?token=OpaqueCaseSensitiveToken',
    'https://android.googleapis.com/gcm/send/OpaqueCaseSensitiveToken',
]);

it('requires both original subscription secrets and encoding to transfer a browser to another account', function (string $changed) {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $payload = pushRegistrationPayload();
    $this->actingAs($first)->postJson(route('web-push.subscriptions.store'), $payload)->assertOk();
    $tampered = $payload;
    if ($changed === 'encoding') {
        $tampered['contentEncoding'] = 'other';
    } else {
        $tampered['keys'][$changed] = 'different-value';
    }
    $this->actingAs($second)->postJson(route('web-push.subscriptions.store'), $tampered)->assertConflict();
    expect(WebPushSubscription::query()->sole()->user_id)->toBe($first->id)
        ->and(WebPushSubscription::query()->sole()->auth_token)->toBe($payload['keys']['auth']);

    $this->postJson(route('web-push.subscriptions.store'), $payload)->assertOk();
    expect(WebPushSubscription::query()->count())->toBe(1)
        ->and(WebPushSubscription::query()->sole()->user_id)->toBe($second->id);
    $this->actingAs($first)->deleteJson(route('web-push.subscriptions.destroy'), ['endpoint' => $payload['endpoint']])
        ->assertOk()->assertJsonPath('deleted', 0);
})->with(['p256dh', 'auth', 'encoding']);

it('blocks every private reserved multicast mapped and transition DNS answer before the wire boundary', function (string $address) {
    [$policy] = syntheticPushPolicy(['8.8.8.8', $address]);
    $transport = syntheticPushTransport($policy);
    expect(fn () => $transport->post('https://fcm.googleapis.com/fcm/send/opaque', 'body', []))
        ->toThrow(InvalidArgumentException::class, 'unapproved address')
        ->and($transport->wireCalls)->toBe([]);
})->with([
    '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.169.254',
    '100.64.0.1', '192.0.0.9', '192.88.99.2', '192.0.2.1', '224.0.0.1', '240.0.0.1', '0.0.0.0',
    '::1', '::', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', 'ff02::1',
    '64:ff9b::7f00:1', '2002:7f00:1::', '2001::1', '2001:db8::1', '2001:20::1', '3ffe::1', '3fff::1',
]);

it('fails closed for empty DNS answers', function () {
    [$policy] = syntheticPushPolicy([]);
    expect(fn () => $policy->resolve('https://fcm.googleapis.com/sub'))
        ->toThrow(InvalidArgumentException::class, 'no approved address');
});

it('pins one validated address and enforces HTTPS TLS no proxies no redirects and bounded responses', function (string $address, string $pin) {
    [$policy, $resolver] = syntheticPushPolicy([$address]);
    $transport = syntheticPushTransport($policy);
    $transport->response = 'ok';
    $response = $transport->post('https://FCM.GOOGLEAPIS.COM/fcm/send/TokenABC?Case=X', 'encrypted', ['TTL: 60']);
    // A later DNS change cannot influence the address already handed to cURL.
    $resolver->answers = ['127.0.0.1'];
    $wire = $transport->wireCalls[0];
    expect($resolver->calls)->toBe(1)
        ->and($wire['endpoint'])->toBe('https://fcm.googleapis.com/fcm/send/TokenABC?Case=X')
        ->and($wire['options'][CURLOPT_RESOLVE])->toBe([$pin])
        ->and($wire['options'][CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($wire['options'][CURLOPT_MAXREDIRS])->toBe(0)
        ->and($wire['options'][CURLOPT_PROXY])->toBe('')
        ->and($wire['options'][CURLOPT_SSL_VERIFYPEER])->toBeTrue()
        ->and($wire['options'][CURLOPT_SSL_VERIFYHOST])->toBe(2)
        ->and($wire['options'][CURLOPT_CONNECTTIMEOUT])->toBe(10)
        ->and($wire['options'][CURLOPT_TIMEOUT])->toBe(20)
        ->and($response)->toBe(['status' => 201, 'body' => 'ok']);
    if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
        expect($wire['options'][constant('CURLOPT_PROTOCOLS_STR')])->toBe('HTTPS')
            ->and($wire['options'][constant('CURLOPT_REDIR_PROTOCOLS_STR')])->toBe('HTTPS');
    } else {
        expect($wire['options'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTPS)
            ->and($wire['options'][CURLOPT_REDIR_PROTOCOLS])->toBe(CURLPROTO_HTTPS);
    }
})->with([
    ['8.8.8.8', 'fcm.googleapis.com:443:8.8.8.8'],
    ['2606:4700:4700::1111', 'fcm.googleapis.com:443:[2606:4700:4700::1111]'],
]);

it('does not follow a redirect and refuses an oversized response at the wire boundary', function () {
    [$policy] = syntheticPushPolicy(['8.8.8.8']);
    $transport = syntheticPushTransport($policy);
    $transport->status = 302;
    expect($transport->post('https://fcm.googleapis.com/sub', 'encrypted', [])['status'])->toBe(302)
        ->and($transport->wireCalls)->toHaveCount(1);
    $transport->response = str_repeat('x', 65537);
    expect(fn () => $transport->post('https://fcm.googleapis.com/sub', 'encrypted', []))
        ->toThrow(RuntimeException::class, 'size limit');
});

it('blocks unsafe legacy subscriptions at send time without logging their endpoint tokens', function () {
    [$policy] = syntheticPushPolicy(['8.8.8.8']);
    $transport = syntheticPushTransport($policy);
    $this->app->instance(PushEndpointPolicy::class, $policy);
    $this->app->instance(PushHttpTransport::class, $transport);
    config(['services.web_push.public_key' => 'configured', 'services.web_push.private_key' => 'configured', 'services.web_push.subject' => 'mailto:test@example.invalid']);
    $subscription = WebPushSubscription::query()->create([
        'user_id' => User::factory()->create()->id, 'endpoint' => 'http://127.0.0.1/secret-endpoint-token',
        'public_key' => 'unused', 'auth_token' => 'unused', 'content_encoding' => 'aes128gcm',
    ]);
    Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), 'secret-endpoint-token')
        && isset($context['exception_class']) && ! isset($context['error']));
    expect(app(WebPushService::class)->sendToSubscription($subscription, ['body' => 'private']))->toBeFalse()
        ->and($transport->wireCalls)->toBe([]);
});

it('sends a locally encrypted valid subscription only through the guarded transport', function () {
    [$policy, $resolver] = syntheticPushPolicy(['8.8.8.8']);
    $transport = syntheticPushTransport($policy);
    $this->app->instance(PushEndpointPolicy::class, $policy);
    $this->app->instance(PushHttpTransport::class, $transport);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $private);
    $ec = openssl_pkey_get_details($key)['ec'];
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $public = $encode("\x04".$ec['x'].$ec['y']);
    config(['services.web_push.public_key' => $public, 'services.web_push.private_key' => $private, 'services.web_push.subject' => 'mailto:test@example.invalid']);
    $subscription = WebPushSubscription::query()->create([
        'user_id' => User::factory()->create()->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/opaque',
        'public_key' => $public, 'auth_token' => $encode(random_bytes(16)), 'content_encoding' => 'aes128gcm',
    ]);
    expect(app(WebPushService::class)->sendToSubscription($subscription, ['body' => 'private plaintext']))->toBeTrue()
        ->and($resolver->calls)->toBe(1)
        ->and($transport->wireCalls)->toHaveCount(1)
        ->and($transport->wireCalls[0]['options'][CURLOPT_POSTFIELDS])->not->toContain('private plaintext')
        ->and($subscription->fresh()->last_used_at)->not->toBeNull();
});
