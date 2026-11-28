<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Events\WebhookReceived;

class StripeWebhookTestCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake([WebhookReceived::class]);
    $this->app->bind(ValidateCsrfToken::class, StripeWebhookTestCsrfMiddleware::class);
});

it('refuses an unconfigured webhook before dispatching billing events', function () {
    config()->set('cashier.webhook.secret', null);

    $this->postJson('/stripe/webhook', ['type' => 'unsupported.test', 'data' => ['object' => []]])
        ->assertServiceUnavailable();

    Event::assertNotDispatched(WebhookReceived::class);
});

it('rejects unsigned webhooks before dispatching billing events', function () {
    config()->set('cashier.webhook.secret', 'whsec_test_only');

    $this->postJson('/stripe/webhook', ['type' => 'unsupported.test', 'data' => ['object' => []]])
        ->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
});

it('admits a signed webhook without a browser csrf token', function () {
    config()->set('cashier.webhook.secret', 'whsec_test_only');
    $payload = json_encode(['type' => 'unsupported.test', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
    $time = time();
    $signature = hash_hmac('sha256', $time.'.'.$payload, 'whsec_test_only');

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
    ], $payload)->assertOk();

    Event::assertDispatched(WebhookReceived::class);
});

it('rejects stale signatures and modified signed payloads', function (bool $stale) {
    config()->set('cashier.webhook.secret', 'whsec_test_only');
    $payload = json_encode(['type' => 'unsupported.test', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
    $time = time() - ($stale ? 1000 : 0);
    $signature = hash_hmac('sha256', $time.'.'.$payload, 'whsec_test_only');

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
    ], $payload.($stale ? '' : ' '))->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
})->with([true, false]);

it('retains browser csrf protection on checkout', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/subscription/checkout', ['tier' => 'basic', 'billing_period' => 'monthly'])
        ->assertStatus(419);
});
