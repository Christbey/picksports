<?php

namespace App\Services;

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
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
            ])
            ->filter(fn (array $recommendation) => $recommendation['expected_value'] > 0)
            ->values()
            ->all();
    }

    protected function sendAlertsToUsers(PredictionDeliveryContext $prediction, string $sport, float $expectedValue, string $recommendation, ?int $betDecisionId = null): int
    {
        // Find the notification template
        $template = $this->templateDefaultService->resolve('betting_value_alert');

        $users = User::query()
            ->with('alertPreference')
            ->whereHas('alertPreference', function ($query) use ($sport, $expectedValue) {
                $query->where('enabled', true)
                    ->where('minimum_edge', '<=', $expectedValue)
                    ->whereJsonContains('sports', strtolower($sport));
            })
            ->get();

        $alertsSent = 0;

        foreach ($users as $user) {
            // TIER CHECK 1: Verify user's tier allows email alerts
            if (! $user->hasTierFeature('email_alerts')) {
                continue;
            }

            // TIER CHECK 2: Verify sport is accessible in user's tier
            if (! $user->canAccessSport($sport)) {
                continue;
            }

            // TIER CHECK 3: Verify user hasn't exceeded daily alert limit
            if ($user->hasReachedDailyAlertLimit()) {
                continue;
            }

            // Existing checks
            if (! $user->alertPreference->isWithinTimeWindow()) {
                continue;
            }

            // Check if user has enabled this template
            if ($template && ! $user->alertPreference->shouldReceiveTemplate($template->id)) {
                continue;
            }

            // Send the notification
            $user->notify(new BettingValueAlert(
                $prediction->source,
                $sport,
                $expectedValue,
                $recommendation,
                $template,
                $betDecisionId,
            ));

            // Record that alert was sent for tier limit tracking
            UserAlertSent::create([
                'user_id' => $user->id,
                'sport' => strtolower($sport),
                'alert_type' => 'betting_value',
                'prediction_id' => $prediction->source->getKey(),
                'prediction_type' => $prediction->source::class,
                'expected_value' => $expectedValue,
                'sent_at' => now(),
            ]);

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
