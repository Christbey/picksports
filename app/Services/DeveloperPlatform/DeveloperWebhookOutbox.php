<?php

namespace App\Services\DeveloperPlatform;

use App\Models\DeveloperOrganization;
use App\Models\DeveloperWebhookOutboxEvent;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeveloperWebhookOutbox
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        DeveloperOrganization $organization,
        string $eventId,
        string $eventType,
        array $payload,
        ?DateTimeInterface $occurredAt = null,
    ): DeveloperWebhookOutboxEvent {
        $eventId = trim($eventId);
        $eventType = trim($eventType);

        if ($eventId === '' || strlen($eventId) > 120 || $eventType === '' || strlen($eventType) > 120) {
            throw new InvalidArgumentException('Webhook events require bounded event identifiers and event types.');
        }

        $canonicalPayload = $this->canonicalize($payload);
        $payloadJson = json_encode($canonicalPayload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($organization, $eventId, $eventType, $canonicalPayload, $payloadJson, $occurredAt): DeveloperWebhookOutboxEvent {
            $event = DeveloperWebhookOutboxEvent::query()->firstOrCreate(
                [
                    'developer_organization_id' => $organization->getKey(),
                    'event_id' => $eventId,
                ],
                [
                    'event_type' => $eventType,
                    'payload' => $canonicalPayload,
                    'payload_hash' => hash('sha256', $payloadJson),
                    'occurred_at' => $occurredAt ?? now(),
                ],
            );

            if (! $event->wasRecentlyCreated) {
                if ($event->event_type !== $eventType || ! hash_equals($event->payload_hash, hash('sha256', $payloadJson))) {
                    throw new InvalidArgumentException('The webhook event identifier was already used with different content.');
                }

                return $event;
            }

            $organization->webhookEndpoints()
                ->where('status', 'active')
                ->get()
                ->filter(fn ($endpoint): bool => $endpoint->subscribesTo($eventType))
                ->each(fn ($endpoint) => $event->deliveries()->create([
                    'developer_webhook_endpoint_id' => $endpoint->getKey(),
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                ]));

            return $event->load('deliveries');
        }, 3);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (Arr::isAssoc($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
