<?php

namespace App\Notifications\Onboarding;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MethodologyEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Understanding Our Prediction Methodology')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('You might be wondering: how do we generate our predictions? Let me walk you through our approach.')
            ->line('**Our Three-Pillar System:**')
            ->line('**1. Elo Rating System** - We track team strength over time, adjusting after every game to reflect current form.')
            ->line('**2. Advanced Metrics** - We analyze offensive efficiency, defensive rating, pace, and situational factors like rest days and travel.')
            ->line('**3. Market Analysis** - We compare our predictions against Vegas lines to identify value opportunities.')
            ->line('Every prediction includes a confidence score, so you know exactly how strong the data supports each pick.')
            ->action('View Our Methodology', url('/methodology'))
            ->line('Understanding our process helps you make informed decisions about which picks to follow.')
            ->line('Tomorrow, we\'ll show you how to identify the highest-value bets.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_methodology',
            'email_number' => 2,
        ];
    }
}
