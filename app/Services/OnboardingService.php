<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserOnboardingProgress;
use App\Notifications\Onboarding\MaximizeSubscriptionEmail;
use App\Notifications\Onboarding\MethodologyEmail;
use App\Notifications\Onboarding\ValueBetsEmail;
use App\Notifications\Onboarding\WeeklyTipsEmail;
use App\Notifications\Onboarding\WelcomeEmail;
use Illuminate\Support\Facades\Notification;

class OnboardingService
{
    protected const EMAIL_SCHEDULE = [
        1 => ['notification' => WelcomeEmail::class, 'delay_minutes' => 0],          // Immediate
        2 => ['notification' => MethodologyEmail::class, 'delay_minutes' => 2880],    // 2 days
        3 => ['notification' => ValueBetsEmail::class, 'delay_minutes' => 7200],      // 5 days
        4 => ['notification' => MaximizeSubscriptionEmail::class, 'delay_minutes' => 12960], // 9 days
        5 => ['notification' => WeeklyTipsEmail::class, 'delay_minutes' => 20160],    // 14 days
    ];

    protected const ONBOARDING_STEPS = [
        'welcome' => 'Welcome & Overview',
        'sport_selection' => 'Select Your Sports',
        'alert_setup' => 'Set Up Alerts',
        'methodology_review' => 'Review Methodology',
    ];

    public function startOnboarding(User $user): UserOnboardingProgress
    {
        $progress = UserOnboardingProgress::firstOrCreate(
            ['user_id' => $user->id],
            [
                'current_step' => 'welcome',
                'completed_steps' => [],
                'progress_percentage' => 0,
                'started_at' => now(),
                'last_activity_at' => now(),
                'welcome_emails_sent' => 0,
            ]
        );

        // Only schedule emails if this is a newly created record
        if ($progress->wasRecentlyCreated) {
            $this->scheduleWelcomeEmailSeries($user);
        }

        return $progress;
    }

    public function scheduleWelcomeEmailSeries(User $user): void
    {
        foreach (self::EMAIL_SCHEDULE as $emailNumber => $config) {
            $notificationClass = $config['notification'];
            $delayMinutes = $config['delay_minutes'];

            if ($delayMinutes === 0) {
                Notification::send($user, new $notificationClass);
            } else {
                Notification::send($user, (new $notificationClass)->delay(now()->addMinutes($delayMinutes)));
            }
        }
    }

    public function completeStep(User $user, string $stepName, ?array $stepData = null): UserOnboardingProgress
    {
        $progress = $user->onboardingProgress;

        if (! $progress) {
            $progress = $this->startOnboarding($user);
        }

        if (isset(self::ONBOARDING_STEPS[$stepName])) {
            $progress->completeStep($stepName);

            if ($stepData) {
                $progress->step_data = array_merge($progress->step_data ?? [], [$stepName => $stepData]);
                $progress->save();
            }

            // Enable alert preferences when completing alert_setup step
            if ($stepName === 'alert_setup') {
                $this->enableAlertPreferences($user, $progress);
            }
        }

        return $progress->fresh();
    }

    public function savePersonalizationData(User $user, array $data): UserOnboardingProgress
    {
        $progress = $user->onboardingProgress ?? $this->startOnboarding($user);

        if (isset($data['favorite_sports'])) {
            $progress->favorite_sports = $data['favorite_sports'];
        }

        if (isset($data['betting_experience'])) {
            $progress->betting_experience = $data['betting_experience'];
        }

        if (isset($data['interests'])) {
            $progress->interests = $data['interests'];
        }

        if (isset($data['goals'])) {
            $progress->goals = $data['goals'];
        }

        $progress->last_activity_at = now();
        $progress->save();

        return $progress->fresh();
    }

    public function getQuickStartChecklist(User $user): array
    {
        $progress = $user->onboardingProgress;
        $completedSteps = $progress?->completed_steps ?? [];

        return [
            [
                'id' => 'sport_selection',
                'title' => 'Set sport preferences',
                'description' => 'Choose which sports you want to follow',
                'completed' => in_array('sport_selection', $completedSteps),
                'url' => '/settings/onboarding',
            ],
            [
                'id' => 'alert_setup',
                'title' => 'Enable notifications',
                'description' => 'Get alerts for high-value betting opportunities',
                'completed' => in_array('alert_setup', $completedSteps),
                'url' => '/settings/alert-preferences',
            ],
            [
                'id' => 'first_bet',
                'title' => 'Log first bet',
                'description' => 'Start tracking your performance',
                'completed' => $user->bets()->exists(),
                'url' => '/my-bets',
            ],
            [
                'id' => 'methodology_review',
                'title' => 'View methodology',
                'description' => 'Understand how we generate predictions',
                'completed' => in_array('methodology_review', $completedSteps),
                'url' => '/methodology',
            ],
            [
                'id' => 'performance_check',
                'title' => 'Check performance stats',
                'description' => 'Review your betting analytics',
                'completed' => $user->bets()->count() >= 3,
                'url' => '/performance',
            ],
        ];
    }

    public function getOnboardingProgress(User $user): array
    {
        $progress = $user->onboardingProgress;

        if (! $progress) {
            return [
                'started' => false,
                'current_step' => null,
                'progress_percentage' => 0,
                'completed' => false,
            ];
        }

        return [
            'started' => true,
            'current_step' => $progress->current_step,
            'progress_percentage' => $progress->progress_percentage,
            'completed' => $progress->isComplete(),
            'completed_steps' => $progress->completed_steps ?? [],
            'started_at' => $progress->started_at?->toISOString(),
            'completed_at' => $progress->completed_at?->toISOString(),
            'is_abandoned' => $progress->isAbandoned(),
            'favorite_sports' => $progress->favorite_sports,
            'betting_experience' => $progress->betting_experience,
        ];
    }

    public function skipOnboarding(User $user): UserOnboardingProgress
    {
        $progress = $user->onboardingProgress ?? $this->startOnboarding($user);

        $progress->markComplete();

        return $progress->fresh();
    }

    public function getAvailableSteps(): array
    {
        return self::ONBOARDING_STEPS;
    }

    protected function enableAlertPreferences(User $user, UserOnboardingProgress $progress): void
    {
        // Use favorite sports from onboarding, or default to all sports
        $sports = $progress->favorite_sports ?? ['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'];

        UserAlertPreference::updateOrCreate(
            ['user_id' => $user->id],
            [
                'enabled' => true,
                'sports' => $sports,
                'notification_types' => ['email'],
                'minimum_edge' => 0.00,
                'time_window_start' => '08:00',
                'time_window_end' => '22:00',
                'digest_mode' => 'realtime',
                'digest_time' => null,
            ]
        );
    }
}
