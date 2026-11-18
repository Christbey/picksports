<?php

namespace App\Services;

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
use App\Notifications\BettingValueAlertChannelResolver;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\Predictions\PredictionDeliverySource;

class AlertService
{
    private const SPORT_ORDER = ['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'];

    public function __construct(
        private readonly NotificationTemplateDefaultService $templateDefaultService,
        private readonly GameBettingRecommendationService $gameBettingRecommendationService,
        private readonly PredictionDeliverySource $predictions,
    ) {}

    public function checkForValueOpportunities(string $sport): int
    {
        $sport = strtolower($sport);

        $predictions = $this->predictions->forAlerts($sport);

        $alertsSent = 0;

        foreach ($predictions as $prediction) {
            $opportunities = $this->analyzeOpportunities($prediction);

            foreach ($opportunities as $opportunity) {
                $alertsSent += $this->sendAlertsToUsers(
                    $prediction,
                    $sport,
                    $opportunity['expected_value'],
                    $opportunity['recommendation'],
                    $opportunity['bet_decision_id'] ?? null,
                    $opportunity['selection'],
                );
            }
        }

        return $alertsSent;
    }

    protected function analyzeOpportunities(PredictionDeliveryContext $prediction): array
    {
        $game = $prediction->game;
        if (! $prediction->isCanonical() && ! $game->odds_data) {
            return [];
        }

        return collect($this->gameBettingRecommendationService->forPrediction($prediction))
            ->filter(fn (array $recommendation) => is_numeric($recommendation['edge'] ?? null))
            ->map(fn (array $recommendation) => [
                'bet_decision_id' => $recommendation['bet_decision_id'] ?? null,
                'expected_value' => round((float) $recommendation['edge'], 2),
                'recommendation' => (string) ($recommendation['recommendation'] ?? 'No recommendation available'),
                'selection' => $recommendation,
            ])
            ->filter(fn (array $recommendation) => $recommendation['expected_value'] > 0)
            ->values()
            ->all();
    }

    protected function sendAlertsToUsers(PredictionDeliveryContext $prediction, string $sport, float $expectedValue, string $recommendation, ?int $betDecisionId = null, array $selection = []): int
    {
        // Find the notification template
        $template = $this->templateDefaultService->resolve('betting_value_alert');

        $users = User::query()
            ->with('alertPreference')
            ->whereHas('alertPreference', function ($query) use ($sport) {
                $query->where('enabled', true)
                    ->whereJsonContains('sports', strtolower($sport));
            })
            ->get();

        $alertsSent = 0;

        foreach ($users as $user) {
            $identity = $betDecisionId !== null ? 'decision:'.$betDecisionId : 'legacy:'.hash('sha256', implode('|', [
                $prediction->source::class, $prediction->source->getKey(), $prediction->source->updated_at?->toIso8601String(),
                $selection['type'] ?? '', $recommendation,
            ]));
            $notification = new BettingValueAlert(
                $prediction->source,
                $sport,
                $expectedValue,
                $recommendation,
                $template,
                $betDecisionId,
                $selection,
                $identity,
            );
            $reason = app(BettingAlertEligibility::class)->reason($user, $sport, $expectedValue, $selection, $template);
            if ($reason !== null) {
                if ($reason === 'edge_threshold_unit_unconfigured') {
                    $deliveries = app(NotificationDeliveryService::class);
                    foreach (app(BettingValueAlertChannelResolver::class)->resolve($user) as $channel) {
                        $record = $deliveries->record('betting_value_alert', $channel === 'mail' ? hash('sha256', strtolower(trim($user->email))) : 'user:'.$user->id,
                            $channel, $notification->deliveryKey($user, $channel), ['source_type' => $prediction->source::class, 'source_id' => (string) $prediction->source->getKey()]);
                        $deliveries->markSuppressed($record, $reason);
                    }
                }

                continue;
            }
            $reserved = $user->getConnection()->transaction(function () use ($user, $sport, $expectedValue, $prediction, $identity): bool {
                $lockedUser = User::query()->lockForUpdate()->find($user->id);
                $key = hash('sha256', $user->id.':'.$identity);
                if ($lockedUser === null || $lockedUser->hasReachedDailyAlertLimit()
                    || UserAlertSent::query()->where('deduplication_key', $key)->exists()) {
                    return false;
                }
                UserAlertSent::query()->create([
                    'user_id' => $user->id,
                    'sport' => strtolower($sport),
                    'alert_type' => 'betting_value',
                    'prediction_id' => $prediction->source->getKey(),
                    'prediction_type' => $prediction->source::class,
                    'expected_value' => $expectedValue,
                    'sent_at' => now(),
                    'deduplication_key' => $key,
                ]);

                return true;
            });
            if (! $reserved) {
                continue;
            }
            $deliveries = app(NotificationDeliveryService::class);
            $records = [];
            foreach (app(BettingValueAlertChannelResolver::class)->resolve($user) as $channel) {
                $record = $deliveries->record('betting_value_alert', $channel === 'mail' ? hash('sha256', strtolower(trim($user->email))) : 'user:'.$user->id,
                    $channel, $notification->deliveryKey($user, $channel), ['source_type' => $prediction->source::class, 'source_id' => (string) $prediction->source->getKey()]);
                $deliveries->markDispatched($record);
                $records[] = $record;
            }
            try {
                $user->notify($notification);
            } catch (\Throwable $error) {
                foreach ($records as $record) {
                    $deliveries->markFailed($record, 'dispatch_failed');
                }
                throw $error;
            }

            $alertsSent++;
        }

        return $alertsSent;
    }

    public function checkAllSports(): array
    {
        $results = [];

        foreach (self::SPORT_ORDER as $sport) {
            $results[$sport] = $this->checkForValueOpportunities($sport);
        }

        return $results;
    }
}
