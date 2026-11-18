<?php

namespace App\Notifications\Channels;

use App\Services\WebPushService;
use Illuminate\Notifications\Notification;
use RuntimeException;

class WebPushChannel
{
    public function __construct(private readonly WebPushService $webPushService) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'webPushSubscriptions')) {
            return;
        }

        if (! method_exists($notification, 'toWebPush')) {
            return;
        }

        $payload = $notification->toWebPush($notifiable);

        if (! is_array($payload)) {
            return;
        }

        $result = $this->webPushService->sendToUser($notifiable, $payload);
        if (($result['sent'] ?? 0) === 0) {
            throw new RuntimeException('No web push subscription accepted the notification.');
        }
    }
}
