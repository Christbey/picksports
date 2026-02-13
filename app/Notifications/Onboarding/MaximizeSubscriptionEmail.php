<?php

namespace App\Notifications\Onboarding;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaximizeSubscriptionEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tier = $notifiable->subscriptionTier();
        $tierName = $tier ? $tier->name : 'Free';

        return (new MailMessage)
            ->subject('Get the Most Out of Your '.config('app.name').' Subscription')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('You\'re currently on our **'.$tierName.' plan**. Let me show you how to maximize your subscription.')
            ->line('**Features you should be using:**')
            ->line('• **Custom Alerts** - Get notified when high-value opportunities appear for your favorite sports')
            ->line('• **Performance Tracking** - Log your bets and see detailed ROI analytics')
            ->line('• **Advanced Filters** - Sort predictions by confidence, expected value, or sport')
            ->line('• **Historical Data** - Review past predictions to see our track record')
            ->action('Manage Alert Settings', url('/account/alerts'))
            ->line('**Quick wins:**')
            ->line('1. Enable email alerts for +5% EV or higher')
            ->line('2. Follow 2-3 sports consistently rather than betting everything')
            ->line('3. Check the dashboard daily for fresh predictions')
            ->line('Need help setting something up? Reply to this email and I\'ll walk you through it.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_maximize',
            'email_number' => 4,
        ];
    }
}
