<?php

namespace App\Services;

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use Illuminate\Database\Eloquent\Model;

class AlertService
{
    protected const SPORTS_MODELS = [
        'nfl' => ['game' => Game::class, 'prediction' => Prediction::class],
        'nba' => ['game' => \App\Models\NBA\Game::class, 'prediction' => \App\Models\NBA\Prediction::class],
        'cbb' => ['game' => \App\Models\CBB\Game::class, 'prediction' => \App\Models\CBB\Prediction::class],
        'wcbb' => ['game' => \App\Models\WCBB\Game::class, 'prediction' => \App\Models\WCBB\Prediction::class],
        'mlb' => ['game' => \App\Models\MLB\Game::class, 'prediction' => \App\Models\MLB\Prediction::class],
        'cfb' => ['game' => \App\Models\CFB\Game::class, 'prediction' => \App\Models\CFB\Prediction::class],
        'wnba' => ['game' => \App\Models\WNBA\Game::class, 'prediction' => \App\Models\WNBA\Prediction::class],
    ];

    public function __construct(
        private readonly NotificationTemplateDefaultService $templateDefaultService,
        private readonly GameBettingRecommendationService $gameBettingRecommendationService,
    ) {}

    public function checkForValueOpportunities(string $sport): int
    {
        $sport = strtolower($sport);

        if (! isset(self::SPORTS_MODELS[$sport])) {
            return 0;
        }

        $models = self::SPORTS_MODELS[$sport];
        $predictionModel = $models['prediction'];

        $predictions = $predictionModel::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query) {
                $query->where('game_date', '>', now())
                    ->whereNotNull('odds_data')
                    ->where('status', '!=', 'completed');
            })
            ->get();

        $alertsSent = 0;

        foreach ($predictions as $prediction) {
            $opportunities = $this->analyzeOpportunities($prediction);

            foreach ($opportunities as $opportunity) {
                $alertsSent += $this->sendAlertsToUsers(
                    $prediction,
                    $sport,
                    $opportunity['expected_value'],
                    $opportunity['recommendation']
                );
            }
        }

        return $alertsSent;
    }

    protected function analyzeOpportunities(Model $prediction): array
    {
        $game = $prediction->game;
        if (! $game || ! $game->odds_data) {
            return [];
        }

        $sport = strtolower($this->inferSportFromPrediction($prediction));

        return collect($this->gameBettingRecommendationService->forGame($game, $sport))
            ->filter(fn (array $recommendation) => is_numeric($recommendation['edge'] ?? null))
            ->map(fn (array $recommendation) => [
                'expected_value' => round((float) $recommendation['edge'], 2),
                'recommendation' => (string) ($recommendation['recommendation'] ?? 'No recommendation available'),
            ])
            ->filter(fn (array $recommendation) => $recommendation['expected_value'] > 0)
            ->values()
            ->all();
    }

    protected function inferSportFromPrediction(Model $prediction): string
    {
        foreach (self::SPORTS_MODELS as $sport => $models) {
            if (($models['prediction'] ?? null) === $prediction::class) {
                return $sport;
            }
        }

        return '';
    }

    protected function sendAlertsToUsers(Model $prediction, string $sport, float $expectedValue, string $recommendation): int
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
                $prediction,
                $sport,
                $expectedValue,
                $recommendation,
                $template
            ));

            // Record that alert was sent for tier limit tracking
            UserAlertSent::create([
                'user_id' => $user->id,
                'sport' => strtolower($sport),
                'alert_type' => 'betting_value',
                'prediction_id' => $prediction->id,
                'prediction_type' => get_class($prediction),
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

        foreach (array_keys(self::SPORTS_MODELS) as $sport) {
            $results[$sport] = $this->checkForValueOpportunities($sport);
        }

        return $results;
    }
}
