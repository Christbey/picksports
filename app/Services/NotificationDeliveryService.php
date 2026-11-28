<?php

namespace App\Services;

use App\Models\NotificationDelivery;

class NotificationDeliveryService
{
    public function record(string $type, string $recipientKey, string $channel, string $deduplicationKey, array $attributes = []): NotificationDelivery
    {
        return NotificationDelivery::query()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            [...$attributes, 'type' => $type, 'recipient_key' => $recipientKey, 'channel' => $channel, 'status' => 'planned'],
        );
    }

    public function markDispatched(NotificationDelivery|string $delivery, ?string $reasonCode = null): void
    {
        $this->transition($delivery, 'dispatched', 'dispatched_at', $reasonCode);
    }

    public function claim(NotificationDelivery $delivery): bool
    {
        return NotificationDelivery::query()->whereKey($delivery->id)->whereNull('accepted_at')
            ->whereIn('status', ['planned', 'dispatched'])->update([
                'status' => 'sending', 'reason_code' => null,
            ]) === 1;
    }

    public function markAccepted(NotificationDelivery|string $delivery, ?string $reasonCode = null): void
    {
        $this->transition($delivery, 'accepted', 'accepted_at', $reasonCode);
    }

    public function markFailed(NotificationDelivery|string $delivery, ?string $reasonCode = null): void
    {
        // A transport/dispatch exception does not establish non-delivery.
        // Without a provider receipt, the same key must never be auto-replayed.
        $this->transition($delivery, 'outcome_unknown', 'failed_at', $reasonCode ?? 'transport_outcome_unknown');
    }

    public function markSuppressed(NotificationDelivery|string $delivery, ?string $reasonCode = null): void
    {
        $this->transition($delivery, 'suppressed', 'suppressed_at', $reasonCode);
    }

    private function transition(NotificationDelivery|string $delivery, string $status, string $timestamp, ?string $reason): void
    {
        $query = NotificationDelivery::query()->where('deduplication_key', $delivery instanceof NotificationDelivery ? $delivery->deduplication_key : $delivery);
        $query->whereNull('accepted_at');
        $query->whereIn('status', match ($status) {
            'dispatched' => ['planned'],
            'suppressed' => ['planned', 'dispatched'],
            // Direct mail acceptance can arrive without a prior local claim.
            // Positive transport evidence may also settle an earlier error.
            'accepted' => ['planned', 'dispatched', 'sending', 'failed', 'suppressed', 'outcome_unknown'],
            'outcome_unknown' => ['planned', 'dispatched', 'sending'],
            default => [],
        });
        $query->update(['status' => $status, $timestamp => now(), 'reason_code' => $reason]);
    }
}
