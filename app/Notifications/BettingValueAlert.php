<?php

namespace App\Notifications;

use App\Models\CanonicalPrediction;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\BettingAlertEligibility;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\NotificationDeliveryService;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
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
        public array $selection = [],
        public ?string $deliveryIdentity = null,
    ) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $source = app(PredictionDeliverySource::class);
        if ($this->prediction instanceof CanonicalPrediction) {
            if (! $source->usesCanonical(strtolower($this->sport)) || ! ($this->betDecisionId ?? null)) {
                $this->suppress($notifiable, $channel, 'decision_unavailable');

                return false;
            }
            $context = $source->context($this->prediction, strtolower($this->sport));
            $selection = collect(app(GameBettingRecommendationService::class)->forGame($context->game, strtolower($this->sport)))
                ->first(fn (array $recommendation): bool => ($recommendation['bet_decision_id'] ?? null) === $this->betDecisionId
                    && ($recommendation['prediction_id'] ?? null) === $this->prediction->public_id);
            if ($selection === null) {
                $this->suppress($notifiable, $channel, 'decision_no_longer_playable');

                return false;
            }
            $this->selection = $selection;
            $this->expectedValue = (float) ($selection['edge'] ?? 0);
        } else {
            $game = $this->prediction->game;
            $start = app(SportsDateWindowService::class)->gameDateTimeUtc($game?->game_date, $game?->game_time);
            if ($source->usesCanonical(strtolower($this->sport)) || ! $start?->isFuture()) {
                $this->suppress($notifiable, $channel, 'forecast_source_changed_or_started');

                return false;
            }
            $observedAt = data_get($this->selection ?? [], 'odds_updated_at') ?? $game?->odds_updated_at;
            if ($observedAt === null) {
                $bookmaker = collect(data_get($game?->odds_data, 'bookmakers', []))
                    ->first(fn (array $book): bool => ($book['key'] ?? null) === ($this->selection['bookmaker'] ?? null));
                $observedAt = data_get($bookmaker, 'last_update');
            }
            if ($observedAt === null) {
                $this->suppress($notifiable, $channel, 'missing_quote_observation');

                return false;
            }
            try {
                $freshQuote = CarbonImmutable::parse($observedAt)->betweenIncluded(now()->subHours(36), now());
            } catch (\Throwable) {
                $freshQuote = false;
            }
            if (! $freshQuote) {
                $this->suppress($notifiable, $channel, 'stale_or_invalid_quote_observation');

                return false;
            }
        }
        $currentUser = $notifiable instanceof User ? User::query()->with('alertPreference')->find($notifiable->getKey()) : null;
        $template = $this->template?->fresh();
        $reason = $currentUser === null ? 'recipient_unavailable'
            : ($this->template !== null && $template === null ? 'template_unavailable'
                : app(BettingAlertEligibility::class)->reason($currentUser, $this->sport, $this->expectedValue, $this->selection ?? [], $template, $channel));
        if ($reason !== null) {
            $this->suppress($notifiable, $channel, $reason);

            return false;
        }

        return true;
    }

    public function deliveryKey(object $notifiable, string $channel): string
    {
        $identity = $this->deliveryIdentity ?? 'notification:'.($this->id ?? hash('sha256', $this->prediction::class.':'.$this->prediction->getKey().':'.$this->recommendation));

        return hash('sha256', $identity.':'.($notifiable->id ?? 'anonymous').':'.$channel);
    }

    private function suppress(object $notifiable, string $channel, string $reason): void
    {
        $service = app(NotificationDeliveryService::class);
        $key = $channel === 'mail' ? hash('sha256', strtolower(trim((string) ($notifiable->email ?? '')))) : 'user:'.($notifiable->id ?? 'anonymous');
        $record = $service->record('betting_value_alert', $key, $channel, $this->deliveryKey($notifiable, $channel), [
            'source_type' => $this->prediction::class, 'source_id' => (string) $this->prediction->getKey(),
        ]);
        $service->markSuppressed($record, $reason);
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
            $this->selection ?? [],
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
            $this->selection ?? [],
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
            $this->selection ?? [],
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
            $this->selection ?? [],
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
            $this->selection ?? [],
        );
    }

    private function formatter(): BettingValueAlertFormatter
    {
        return app(BettingValueAlertFormatter::class);
    }
}
