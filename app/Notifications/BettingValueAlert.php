<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;

class BettingValueAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Model $prediction,
        public string $sport,
        public float $expectedValue,
        public string $recommendation,
        public ?NotificationTemplate $template = null
    ) {}

    public function via(object $notifiable): array
    {
        return app(BettingValueAlertChannelResolver::class)->resolve($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->formatter()->toMail(
            $notifiable,
            $this->prediction,
            $this->sport,
            $this->expectedValue,
            $this->recommendation,
            $this->template,
        );
    }

    public function toArray(object $notifiable): array
    {
        return $this->formatter()->toArray(
            $notifiable,
            $this->prediction,
            $this->sport,
            $this->expectedValue,
            $this->recommendation,
            $this->template,
        );
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->formatter()->toWhatsApp(
            $notifiable,
            $this->prediction,
            $this->sport,
            $this->expectedValue,
            $this->recommendation,
            $this->template,
        );
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return $this->formatter()->toVonage(
            $notifiable,
            $this->prediction,
            $this->sport,
            $this->expectedValue,
            $this->recommendation,
            $this->template,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        return $this->formatter()->toWebPush(
            $notifiable,
            $this->prediction,
            $this->sport,
            $this->expectedValue,
            $this->recommendation,
            $this->template,
        );
    }

    private function formatter(): BettingValueAlertFormatter
    {
        return app(BettingValueAlertFormatter::class);
    }
}
