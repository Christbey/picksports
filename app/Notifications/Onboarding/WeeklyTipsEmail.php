<?php

namespace App\Notifications\Onboarding;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyTipsEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Weekly Betting Tips & Best Practices')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Congrats on completing your first two weeks with '.config('app.name').'! Here are some tips to help you stay profitable long-term.')
            ->line('**Bankroll Management 101:**')
            ->line('• Never bet more than 1-3% of your total bankroll on a single game')
            ->line('• Keep a separate betting bankroll - don\'t mix it with your daily finances')
            ->line('• Track every bet (we make this easy with our bet logging feature)')
            ->line('**When to trust the data:**')
            ->line('• High confidence (75%+) + High EV (5%+) = Strong bet')
            ->line('• Low confidence but massive EV = Proceed with caution')
            ->line('• Avoid betting just because a game is on TV')
            ->line('**Weekly habits of successful bettors:**')
            ->line('• Review performance weekly, not daily (avoid emotional reactions)')
            ->line('• Focus on long-term ROI, not individual wins/losses')
            ->line('• Stay disciplined with your unit size')
            ->action('View Your Performance Stats', url('/performance'))
            ->line('Remember: Sports betting is a marathon, not a sprint. Trust the process, manage your bankroll, and let the data guide your decisions.')
            ->line('You\'re all set! We\'ll continue sending you value alerts, but this is the last onboarding email. Good luck!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_weekly_tips',
            'email_number' => 5,
        ];
    }
}
