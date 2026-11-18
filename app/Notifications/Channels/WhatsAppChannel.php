<?php

namespace App\Notifications\Channels;

use App\Services\TwilioWhatsAppService;
use Illuminate\Notifications\Notification;
use RuntimeException;

class WhatsAppChannel
{
    public function __construct(private readonly TwilioWhatsAppService $twilioWhatsAppService) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        if (! is_string($message) || trim($message) === '') {
            return;
        }

        $phoneNumber = (string) ($notifiable->alertPreference?->phone_number ?? '');
        if (trim($phoneNumber) === '') {
            throw new RuntimeException('WhatsApp recipient is unavailable.');
        }

        if (! $this->twilioWhatsAppService->sendMessage($phoneNumber, $message)) {
            throw new RuntimeException('WhatsApp transport did not accept the notification.');
        }
    }
}
