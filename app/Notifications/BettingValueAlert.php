<?php

namespace App\Notifications;

use App\Models\CanonicalPrediction;
use App\Models\NotificationTemplate;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\Predictions\PredictionDeliverySource;
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
        public ?NotificationTemplate $template = null,
        public ?int $betDecisionId = null,
    ) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $source = app(PredictionDeliverySource::class);
        if (! $this->prediction instanceof CanonicalPrediction) {
            return ! $source->usesCanonical(strtolower($this->sport));
        }

        if (! $source->usesCanonical(strtolower($this->sport)) || ! ($this->betDecisionId ?? null)) {
            return false;
        }
        $context = $source->context($this->prediction, strtolower($this->sport));

        return collect(app(GameBettingRecommendationService::class)->forGame($context->game, strtolower($this->sport)))
            ->contains(fn (array $recommendation): bool => ($recommendation['bet_decision_id'] ?? null) === $this->betDecisionId
                && ($recommendation['prediction_id'] ?? null) === $this->prediction->public_id);
    }

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
