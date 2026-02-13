<?php

namespace App\Notifications\Onboarding;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ValueBetsEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('How to Find Value Bets (This is the Secret)')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Here\'s the truth about sports betting: **winning isn\'t about picking more winners, it\'s about finding value**.')
            ->line('**What is a value bet?**')
            ->line('A value bet occurs when our model predicts a different outcome than the Vegas line suggests. The bigger the difference, the more value.')
            ->line('**How to spot them on '.config('app.name').':**')
            ->line('• Look for high Expected Value (EV%) - we calculate this for you')
            ->line('• Check the confidence score - higher is better')
            ->line('• Compare our predicted line vs. the market line')
            ->line('• Pay attention to our recommendation - we highlight the best opportunities')
            ->action('View Today\'s Value Bets', url('/predictions'))
            ->line('**Pro tip:** Set up email alerts for high-value opportunities so you never miss a good bet.')
            ->line('Questions about reading our predictions? Just reply to this email.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_value_bets',
            'email_number' => 3,
        ];
    }
}
