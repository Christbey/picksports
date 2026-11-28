<?php

namespace App\Services;

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
use App\Notifications\BettingValueAlertChannelResolver;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\Predictions\PredictionDeliverySource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class AlertService
{
    private const SPORT_ORDER = ['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'];

    public function __construct(
        private readonly NotificationTemplateDefaultService $templateDefaultService,
        private readonly GameBettingRecommendationService $gameBettingRecommendationService,
        private readonly PredictionDeliverySource $predictions,
        private ?BettingAlertEligibility $eligibility = null,
        private ?NotificationDeliveryService $deliveries = null,
        private ?BettingValueAlertChannelResolver $channels = null,
    ) {
        $this->eligibility ??= app(BettingAlertEligibility::class);
        $this->deliveries ??= app(NotificationDeliveryService::class);
        $this->channels ??= app(BettingValueAlertChannelResolver::class);
    }

    public function checkForValueOpportunities(string $sport): int
    {
        $sport = strtolower($sport);

        $predictions = $this->predictions->forAlerts($sport);

        $alertsSent = 0;
        $template = $this->templateDefaultService->resolve('betting_value_alert');
        $users = $this->recipientQuery($sport)->get();

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
                    $users,
                    $template,
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

    private function recipientQuery(string $sport): Builder
    {
        return User::query()->with(['alertPreference', 'subscriptions'])
            ->whereHas('alertPreference', fn ($query) => $query->where('enabled', true)
                ->whereJsonContains('sports', strtolower($sport)));
    }

    protected function sendAlertsToUsers(PredictionDeliveryContext $prediction, string $sport, float $expectedValue, string $recommendation, ?int $betDecisionId = null, array $selection = [], ?Collection $users = null, ?object $template = null): int
    {
        if ($users === null) {
            $template ??= $this->templateDefaultService->resolve('betting_value_alert');
        }
        $users ??= $this->recipientQuery($sport)->get();

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
            $reason = $this->eligibility->reason($user, $sport, $expectedValue, $selection, $template);
            if ($reason !== null) {
                if ($reason === 'edge_threshold_unit_unconfigured') {
                    $deliveries = $this->deliveries;
                    foreach ($this->channels->resolve($user) as $channel) {
                        $record = $deliveries->record('betting_value_alert', $channel === 'mail' ? hash('sha256', strtolower(trim($user->email))) : 'user:'.$user->id,
                            $channel, $notification->deliveryKey($user, $channel), ['source_type' => $prediction->source::class, 'source_id' => (string) $prediction->source->getKey()]);
                        $deliveries->markSuppressed($record, $reason);
                    }
                }

                continue;
            }
            $connection = $user->getConnection();
            if ($connection !== (new User)->getConnection() || $connection !== (new UserAlertSent)->getConnection()) {
                throw new RuntimeException('Alert reservation requires one current user and reservation connection.');
            }
            $reserved = $connection->transaction(function () use ($connection, $user, $sport, $expectedValue, $prediction, $identity): bool {
                $lockedUser = User::query()->lockForUpdate()->find($user->id);
                $key = hash('sha256', $user->id.':'.$identity);
                if ($lockedUser === null || $lockedUser->hasReachedDailyAlertLimit()
                    || UserAlertSent::query()->useWritePdo()->where('deduplication_key', $key)->exists()) {
                    return false;
                }
                $attributes = [
                    'user_id' => $user->id,
                    'sport' => strtolower($sport),
                    'alert_type' => 'betting_value',
                    'prediction_id' => $prediction->source->getKey(),
                    'prediction_type' => $prediction->source::class,
                    'expected_value' => $expectedValue,
                    'sent_at' => now()->format('Y-m-d H:i:s'),
                    'deduplication_key' => $key,
                ];
                $expected = new UserAlertSent($attributes);
                $expectedDecimal = $expected->expected_value;
                $reservation = UserAlertSent::query()->create($attributes);
                if (! $reservation->exists || ! $reservation->wasRecentlyCreated
                    || $reservation->getConnection() !== $connection || $reservation->getTable() !== 'user_alerts_sent') {
                    throw new RuntimeException('Alert reservation was not persisted.');
                }
                $headerQuery = $connection->table('user_alerts_sent')->useWritePdo()
                    ->where('id', $reservation->getKey())->lockForUpdate()->select('id');
                foreach ($attributes as $field => $value) {
                    $column = $connection->getQueryGrammar()->wrap($field);
                    $maximum = in_array($field, ['user_id', 'prediction_id', 'expected_value'], true)
                        ? 32 : strlen((string) $value) + 1;
                    $headerQuery->selectRaw("CASE WHEN LENGTH({$column}) <= ? THEN {$column} ELSE NULL END AS {$column}", [$maximum]);
                }
                $header = $headerQuery->first();
                if ($header === null) {
                    throw new RuntimeException('Alert reservation disappeared before dispatch.');
                }
                $persisted = (new UserAlertSent)->setRawAttributes((array) $header);
                foreach ($attributes as $field => $value) {
                    $actual = $field === 'expected_value' ? $persisted->expected_value : (string) $header->{$field};
                    $desired = $field === 'expected_value' ? $expectedDecimal : (string) $value;
                    if ($actual !== $desired) {
                        throw new RuntimeException('Alert reservation differs from its requested owner or identity.');
                    }
                }

                return true;
            });
            if (! $reserved) {
                continue;
            }
            $deliveries = $this->deliveries;
            $records = [];
            foreach ($this->channels->resolve($user) as $channel) {
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
