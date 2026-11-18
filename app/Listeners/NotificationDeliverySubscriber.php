<?php

namespace App\Listeners;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\BettingValueAlert;
use App\Notifications\ValidationRegressionAlert;
use App\Services\NotificationDeliveryService;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

class NotificationDeliverySubscriber
{
    public function __construct(private readonly NotificationDeliveryService $deliveries) {}

    public function subscribe(Dispatcher $events): array
    {
        return [NotificationSending::class => 'sending', NotificationSent::class => 'sent', NotificationFailed::class => 'failed'];
    }

    public function sending(NotificationSending $event): ?bool
    {
        $record = $this->record($event);
        if ($event->channel === 'mail' && empty($event->notifiable->routeNotificationFor('mail', $event->notification))) {
            $this->deliveries->markSuppressed($record, 'missing_recipient');

            return false;
        }
        if ($record->accepted_at !== null) {
            return false;
        }
        if ($event->notification instanceof ValidationRegressionAlert
            && (! $event->notifiable instanceof User || ! User::query()->whereKey($event->notifiable->id)->where('is_admin', true)->exists())) {
            $this->deliveries->markSuppressed($record, 'admin_access_removed');

            return false;
        }

        return $this->deliveries->claim($record) ? null : false;
    }

    public function sent(NotificationSent $event): void
    {
        $this->deliveries->markAccepted($this->record($event));
    }

    public function failed(NotificationFailed $event): void
    {
        $this->deliveries->markFailed($this->record($event), 'transport_failed');
    }

    private function record(NotificationSending|NotificationSent|NotificationFailed $event): NotificationDelivery
    {
        $notification = $event->notification;
        $notifiable = $event->notifiable;
        $recipient = $event->channel === 'mail'
            ? hash('sha256', strtolower(trim((string) ($notifiable->email ?? json_encode($notifiable->routeNotificationFor('mail', $notification))))))
            : 'user:'.($notifiable->id ?? hash('sha256', get_debug_type($notifiable)));
        $identity = $notification instanceof ValidationRegressionAlert ? 'validation:'.$notification->run->id : $notification::class.':'.$notification->id;
        $key = $notification instanceof BettingValueAlert
            ? $notification->deliveryKey($notifiable, $event->channel)
            : hash('sha256', $identity.':'.$recipient.':'.$event->channel);
        $type = match (true) {
            $notification instanceof BettingValueAlert => 'betting_value_alert',
            $notification instanceof ValidationRegressionAlert => 'validation_regression',
            default => class_basename($notification),
        };

        return $this->deliveries->record($type, $recipient, $event->channel, $key, [
            'source_type' => $notification::class,
            'source_id' => $notification instanceof ValidationRegressionAlert ? (string) $notification->run->id : (string) $notification->id,
        ]);
    }
}
