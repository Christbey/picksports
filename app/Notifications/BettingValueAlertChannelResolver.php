<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WhatsAppChannel;

class BettingValueAlertChannelResolver
{
    /**
     * @return array<int, string>
     */
    public function resolve(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->alertPreference?->shouldReceiveEmailNotifications()) {
            $channels[] = 'mail';
        }

        if ($notifiable->alertPreference?->shouldReceivePushNotifications()) {
            $channels[] = 'database';
            $channels[] = WebPushChannel::class;
        }

        if ($notifiable->alertPreference?->shouldReceiveSmsNotifications()) {
            $channels[] = 'vonage';
        }

        if ($notifiable->alertPreference?->shouldReceiveWhatsAppNotifications()) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }
}
