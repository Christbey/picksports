<?php

namespace App\Notifications\Channels;

use App\Services\TwilioWhatsAppService;
use Illuminate\Notifications\Notification;

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
        if ($phoneNumber === '') {
            return;
        }

        $this->twilioWhatsAppService->sendMessage($phoneNumber, $message);
    }
}
