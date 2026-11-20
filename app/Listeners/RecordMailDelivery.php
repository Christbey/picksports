<?php

namespace App\Listeners;

use App\Services\NotificationDeliveryService;
use Illuminate\Mail\Events\MessageSent;

class RecordMailDelivery
{
    public function __construct(private readonly NotificationDeliveryService $deliveries) {}

    /**
     * Handle the event.
     */
    public function handle(MessageSent $event): void
    {
        if (isset($event->data['__laravel_notification'])) {
            return;
        }

        $message = $event->message;
        $explicitKey = $message->getHeaders()->get('X-PickSports-Delivery-Key')?->getBodyAsString();
        $type = (string) ($event->data['__laravel_mailable'] ?? 'direct_mail');
        $recipients = array_merge($message->getTo(), $message->getCc(), $message->getBcc());
        $seen = [];

        foreach ($recipients as $recipient) {
            $recipientKey = hash('sha256', strtolower(trim($recipient->getAddress())));
            if (isset($seen[$recipientKey])) {
                continue;
            }
            $seen[$recipientKey] = true;
            $key = $explicitKey && count($seen) === 1
                ? $explicitKey
                : 'mail:'.hash('sha256', $event->sent->getMessageId().'|'.$recipientKey);
            $delivery = $this->deliveries->record($type, $recipientKey, 'mail', $key);
            $this->deliveries->markAccepted($delivery);
        }
    }
}
