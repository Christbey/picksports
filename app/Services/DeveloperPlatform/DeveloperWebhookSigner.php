<?php

namespace App\Services\DeveloperPlatform;

use App\DataTransferObjects\DeveloperPlatform\SignedDeveloperWebhookPayload;
use App\Models\DeveloperWebhookDelivery;
use Illuminate\Support\Arr;

class DeveloperWebhookSigner
{
    public function sign(DeveloperWebhookDelivery $delivery, ?int $timestamp = null): SignedDeveloperWebhookPayload
    {
        $delivery->loadMissing(['endpoint', 'outboxEvent']);
        $timestamp ??= now()->timestamp;
        $event = $delivery->outboxEvent;
        $body = json_encode($this->canonicalize([
            'id' => $event->public_id,
            'event_id' => $event->event_id,
            'type' => $event->event_type,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'data' => $event->payload,
        ]), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            $delivery->endpoint->signing_secret,
        );

        return new SignedDeveloperWebhookPayload($body, [
            'Content-Type' => 'application/json',
            'X-PickSports-Webhook-Id' => $delivery->public_id,
            'X-PickSports-Webhook-Timestamp' => (string) $timestamp,
            'X-PickSports-Webhook-Signature' => 'v1='.$signature,
        ]);
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
