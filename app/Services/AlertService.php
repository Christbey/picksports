<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
use Illuminate\Database\Eloquent\Model;

class AlertService
{
    protected const SPORTS_MODELS = [
        'nfl' => ['game' => \App\Models\NFL\Game::class, 'prediction' => \App\Models\NFL\Prediction::class],
        'nba' => ['game' => \App\Models\NBA\Game::class, 'prediction' => \App\Models\NBA\Prediction::class],
        'cbb' => ['game' => \App\Models\CBB\Game::class, 'prediction' => \App\Models\CBB\Prediction::class],
        'wcbb' => ['game' => \App\Models\WCBB\Game::class, 'prediction' => \App\Models\WCBB\Prediction::class],
        'mlb' => ['game' => \App\Models\MLB\Game::class, 'prediction' => \App\Models\MLB\Prediction::class],
        'cfb' => ['game' => \App\Models\CFB\Game::class, 'prediction' => \App\Models\CFB\Prediction::class],
        'wnba' => ['game' => \App\Models\WNBA\Game::class, 'prediction' => \App\Models\WNBA\Prediction::class],
    ];

    public function __construct(private readonly NotificationTemplateDefaultService $templateDefaultService) {}

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
            ->where('confidence_score', '>=', 60)
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
        $opportunities = [];

        if (! $game->odds_data || ! isset($game->odds_data['bookmakers'][0])) {
            return $opportunities;
        }

        $bookmaker = $game->odds_data['bookmakers'][0];
        $markets = collect($bookmaker['markets'] ?? []);

        $spreadMarket = $markets->firstWhere('key', 'spreads');
        if ($spreadMarket && isset($prediction->predicted_spread)) {
            $spreadEV = $this->calculateSpreadExpectedValue($prediction, $game, $spreadMarket);
            if ($spreadEV['expected_value'] > 0) {
                $opportunities[] = $spreadEV;
            }
        }

        $totalsMarket = $markets->firstWhere('key', 'totals');
        if ($totalsMarket && isset($prediction->predicted_total)) {
            $totalsEV = $this->calculateTotalsExpectedValue($prediction, $game, $totalsMarket);
            if ($totalsEV['expected_value'] > 0) {
                $opportunities[] = $totalsEV;
            }
        }

        $moneylineMarket = $markets->firstWhere('key', 'h2h');
        if ($moneylineMarket && isset($prediction->win_probability)) {
            $moneylineEV = $this->calculateMoneylineExpectedValue($prediction, $game, $moneylineMarket);
            if ($moneylineEV['expected_value'] > 0) {
                $opportunities[] = $moneylineEV;
            }
        }

        return $opportunities;
    }

    protected function calculateSpreadExpectedValue(Model $prediction, Model $game, array $market): array
    {
        $outcomes = $market['outcomes'] ?? [];
        $homeOutcome = collect($outcomes)->firstWhere('name', $game->odds_data['home_team']);
        $awayOutcome = collect($outcomes)->firstWhere('name', $game->odds_data['away_team']);

        if (! $homeOutcome || ! $awayOutcome) {
            return ['expected_value' => 0];
        }

        $predictedSpread = (float) $prediction->predicted_spread;
        $marketSpread = (float) ($homeOutcome['point'] ?? 0);
        $spreadDifference = abs($predictedSpread - $marketSpread);

        if ($spreadDifference < 2) {
            return ['expected_value' => 0];
        }

        $confidence = (float) $prediction->confidence_score;
        $impliedProb = $this->americanOddsToProb($homeOutcome['price']);

        if ($predictedSpread < $marketSpread) {
            $expectedValue = (($confidence / 100) - $impliedProb) * 100;
            $homeTeamName = $this->teamName($game->homeTeam);
            $recommendation = "Bet HOME ({$homeTeamName}) at {$marketSpread}";
        } else {
            $awayImpliedProb = $this->americanOddsToProb($awayOutcome['price']);
            $expectedValue = (((100 - $confidence) / 100) - $awayImpliedProb) * 100;
            $awayTeamName = $this->teamName($game->awayTeam);
            $recommendation = "Bet AWAY ({$awayTeamName}) at ".($marketSpread * -1);
        }

        return [
            'expected_value' => $expectedValue,
            'recommendation' => $recommendation,
        ];
    }

    protected function calculateTotalsExpectedValue(Model $prediction, Model $game, array $market): array
    {
        $outcomes = $market['outcomes'] ?? [];
        $overOutcome = collect($outcomes)->firstWhere('name', 'Over');
        $underOutcome = collect($outcomes)->firstWhere('name', 'Under');

        if (! $overOutcome || ! $underOutcome) {
            return ['expected_value' => 0];
        }

        $predictedTotal = (float) $prediction->predicted_total;
        $marketTotal = (float) ($overOutcome['point'] ?? 0);
        $totalDifference = abs($predictedTotal - $marketTotal);

        if ($totalDifference < 3) {
            return ['expected_value' => 0];
        }

        $confidence = (float) $prediction->confidence_score;
        $overImpliedProb = $this->americanOddsToProb($overOutcome['price']);
        $underImpliedProb = $this->americanOddsToProb($underOutcome['price']);

        if ($predictedTotal > $marketTotal) {
            $expectedValue = (($confidence / 100) - $overImpliedProb) * 100;
            $recommendation = "Bet OVER {$marketTotal}";
        } else {
            $expectedValue = (($confidence / 100) - $underImpliedProb) * 100;
            $recommendation = "Bet UNDER {$marketTotal}";
        }

        return [
            'expected_value' => $expectedValue,
            'recommendation' => $recommendation,
        ];
    }

    protected function calculateMoneylineExpectedValue(Model $prediction, Model $game, array $market): array
    {
        $outcomes = $market['outcomes'] ?? [];
        $homeOutcome = collect($outcomes)->firstWhere('name', $game->odds_data['home_team']);

        if (! $homeOutcome) {
            return ['expected_value' => 0];
        }

        $predictedWinProb = (float) $prediction->win_probability;
        $impliedProb = $this->americanOddsToProb($homeOutcome['price']);
        $confidence = (float) $prediction->confidence_score;

        $probDifference = abs($predictedWinProb - $impliedProb);

        if ($probDifference < 0.05) {
            return ['expected_value' => 0];
        }

        $expectedValue = (($predictedWinProb - $impliedProb) * $confidence) / 10;

        if ($predictedWinProb > $impliedProb) {
            $homeTeamName = $this->teamName($game->homeTeam);
            $priceSign = $homeOutcome['price'] > 0 ? '+' : '';
            $recommendation = "Bet HOME ({$homeTeamName}) ML at {$priceSign}{$homeOutcome['price']}";
        } else {
            $awayTeamName = $this->teamName($game->awayTeam);
            $recommendation = "Bet AWAY ({$awayTeamName}) ML";
        }

        return [
            'expected_value' => $expectedValue,
            'recommendation' => $recommendation,
        ];
    }

    protected function americanOddsToProb(int $odds): float
    {
        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        return abs($odds) / (abs($odds) + 100);
    }

    protected function teamName(?Model $team): string
    {
        if (! $team) {
            return 'Unknown';
        }

        return (string) ($team->name ?? $team->school ?? 'Unknown');
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
