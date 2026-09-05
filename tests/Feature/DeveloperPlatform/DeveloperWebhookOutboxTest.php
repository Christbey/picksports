<?php

use App\Models\DeveloperOrganization;
use App\Models\DeveloperWebhookDelivery;
use App\Models\DeveloperWebhookEndpoint;
use App\Models\DeveloperWebhookOutboxEvent;
use App\Services\DeveloperPlatform\DeveloperWebhookDeliveryState;
use App\Services\DeveloperPlatform\DeveloperWebhookEndpointIssuer;
use App\Services\DeveloperPlatform\DeveloperWebhookOutbox;
use App\Services\DeveloperPlatform\DeveloperWebhookSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('issues webhook endpoints with an encrypted signing secret exposed once', function () {
    $organization = DeveloperOrganization::factory()->create();
    $issued = app(DeveloperWebhookEndpointIssuer::class)->issue(
        $organization,
        'Predictions webhook',
        'https://hooks.example.test/picksports',
        ['prediction.updated', 'prediction.updated'],
    );
    $storedSecret = DB::table('developer_webhook_endpoints')
        ->where('id', $issued->endpoint->id)
        ->value('signing_secret');

    expect($issued->endpoint->signing_secret)->toBe($issued->signingSecret)
        ->and($issued->endpoint->events)->toBe(['prediction.updated'])
        ->and($storedSecret)->not->toBe($issued->signingSecret)
        ->and($issued->endpoint->toArray())->not->toHaveKey('signing_secret');
});

it('atomically fans an idempotent outbox event to subscribed endpoints only', function () {
    $organization = DeveloperOrganization::factory()->create();
    $subscribed = DeveloperWebhookEndpoint::factory()->create([
        'developer_organization_id' => $organization->id,
        'events' => ['prediction.updated'],
    ]);
    DeveloperWebhookEndpoint::factory()->create([
        'developer_organization_id' => $organization->id,
        'events' => ['event.finalized'],
    ]);
    DeveloperWebhookEndpoint::factory()->create([
        'developer_organization_id' => $organization->id,
        'events' => ['*'],
        'status' => 'disabled',
    ]);
    $outbox = app(DeveloperWebhookOutbox::class);

    $event = $outbox->record(
        $organization,
        'prediction:123:updated:7',
        'prediction.updated',
        ['z' => 2, 'a' => ['y' => 1, 'x' => 0]],
    );
    $replayed = $outbox->record(
        $organization,
        'prediction:123:updated:7',
        'prediction.updated',
        ['a' => ['x' => 0, 'y' => 1], 'z' => 2],
    );

    expect($replayed->is($event))->toBeTrue()
        ->and(DeveloperWebhookOutboxEvent::query()->count())->toBe(1)
        ->and(DeveloperWebhookDelivery::query()->count())->toBe(1)
        ->and($event->deliveries->first()->developer_webhook_endpoint_id)->toBe($subscribed->id)
        ->and($event->payload)->toBe(['a' => ['x' => 0, 'y' => 1], 'z' => 2]);

    expect(fn () => $outbox->record(
        $organization,
        'prediction:123:updated:7',
        'prediction.updated',
        ['changed' => true],
    ))->toThrow(InvalidArgumentException::class, 'different content');
});

it('builds deterministic signed webhook payloads without sending HTTP', function () {
    Http::fake();
    $organization = DeveloperOrganization::factory()->create();
    $endpoint = DeveloperWebhookEndpoint::factory()->create([
        'developer_organization_id' => $organization->id,
        'signing_secret' => 'test-signing-secret',
        'events' => ['prediction.updated'],
    ]);
    $event = app(DeveloperWebhookOutbox::class)->record(
        $organization,
        'prediction:456',
        'prediction.updated',
        ['prediction_id' => 456],
    );
    $delivery = $event->deliveries()->where('developer_webhook_endpoint_id', $endpoint->id)->firstOrFail();
    $signed = app(DeveloperWebhookSigner::class)->sign($delivery, 1_800_000_000);
    $expected = 'v1='.hash_hmac('sha256', '1800000000.'.$signed->body, 'test-signing-secret');

    expect($signed->headers['Content-Type'])->toBe('application/json')
        ->and($signed->headers['X-PickSports-Webhook-Id'])->toBe($delivery->public_id)
        ->and($signed->headers['X-PickSports-Webhook-Timestamp'])->toBe('1800000000')
        ->and($signed->headers['X-PickSports-Webhook-Signature'])->toBe($expected)
        ->and(json_decode($signed->body, true))->toMatchArray([
            'event_id' => 'prediction:456',
            'type' => 'prediction.updated',
            'data' => ['prediction_id' => 456],
        ]);

    Http::assertNothingSent();
});

it('tracks retry availability and terminal delivery state without performing delivery', function () {
    $this->travelTo('2026-08-12 12:00:00');
    config()->set('api.developer.webhooks.max_attempts', 2);
    config()->set('api.developer.webhooks.retry_backoff_seconds', [60]);
    $endpoint = DeveloperWebhookEndpoint::factory()->create();
    $event = DeveloperWebhookOutboxEvent::factory()->create([
        'developer_organization_id' => $endpoint->developer_organization_id,
    ]);
    $delivery = DeveloperWebhookDelivery::factory()->create([
        'developer_webhook_endpoint_id' => $endpoint->id,
        'developer_webhook_outbox_event_id' => $event->id,
        'available_at' => now(),
    ]);
    $state = app(DeveloperWebhookDeliveryState::class);

    $retry = $state->markFailed($delivery, 'connection refused');

    expect($retry->status)->toBe('retry')
        ->and($retry->attempts)->toBe(1)
        ->and($retry->available_at->equalTo(now()->addSeconds(60)))->toBeTrue()
        ->and(DeveloperWebhookDelivery::query()->ready()->count())->toBe(0)
        ->and($endpoint->fresh()->last_failure_at)->not->toBeNull();

    $this->travel(61)->seconds();

    expect(DeveloperWebhookDelivery::query()->ready()->count())->toBe(1);

    $dead = $state->markFailed($retry, 'still unavailable', 503);

    expect($dead->status)->toBe('dead')
        ->and($dead->attempts)->toBe(2)
        ->and($dead->response_status)->toBe(503)
        ->and(DeveloperWebhookDelivery::query()->ready()->count())->toBe(0);

    $delivered = DeveloperWebhookDelivery::factory()->create([
        'developer_webhook_endpoint_id' => $endpoint->id,
        'developer_webhook_outbox_event_id' => DeveloperWebhookOutboxEvent::factory()->create([
            'developer_organization_id' => $endpoint->developer_organization_id,
        ])->id,
        'available_at' => now(),
    ]);
    $delivered = $state->markDelivered($delivered, 204);

    expect($delivered->status)->toBe('delivered')
        ->and($delivered->attempts)->toBe(1)
        ->and($delivered->response_status)->toBe(204)
        ->and($delivered->delivered_at)->not->toBeNull()
        ->and($endpoint->fresh()->last_success_at)->not->toBeNull();

    $this->travelBack();
});
