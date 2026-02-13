<?php

namespace App\Notifications\Onboarding;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to '.config('app.name').' - Let\'s Get Started')
            ->greeting('Welcome, '.$notifiable->name.'!')
            ->line('Thank you for joining '.config('app.name').'. We\'re excited to help you make smarter, data-driven betting decisions.')
            ->line('**Here\'s what you can do right now:**')
            ->line('• View today\'s top predictions across all sports')
            ->line('• Explore our methodology and see how we analyze games')
            ->line('• Set up custom alerts for the sports you care about')
            ->line('• Track your betting performance with our analytics')
            ->action('Get Started', url('/dashboard'))
            ->line('Over the next two weeks, we\'ll send you tips to help you get the most out of your subscription.')
            ->line('If you have any questions, just reply to this email. We\'re here to help!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_welcome',
            'email_number' => 1,
        ];
    }
}
