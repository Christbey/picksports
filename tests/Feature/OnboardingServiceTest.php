<?php

use App\Models\User;
use App\Models\UserBet;
use App\Models\UserOnboardingProgress;
use App\Notifications\Onboarding\WelcomeEmail;
use App\Notifications\Onboarding\MethodologyEmail;
use App\Notifications\Onboarding\ValueBetsEmail;
use App\Notifications\Onboarding\MaximizeSubscriptionEmail;
use App\Notifications\Onboarding\WeeklyTipsEmail;
use App\Services\OnboardingService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->service = app(OnboardingService::class);
});

test('startOnboarding creates onboarding progress for user', function () {
    $user = User::factory()->create();

    $progress = $this->service->startOnboarding($user);

    expect($progress)->toBeInstanceOf(UserOnboardingProgress::class)
        ->and($progress->user_id)->toBe($user->id)
        ->and($progress->current_step)->toBe('welcome')
        ->and($progress->progress_percentage)->toBe(0)
        ->and($progress->completed_steps)->toBe([]);

    $this->assertDatabaseHas('user_onboarding_progress', [
        'user_id' => $user->id,
        'current_step' => 'welcome',
        'progress_percentage' => 0,
    ]);
});

test('startOnboarding schedules welcome email series', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->service->startOnboarding($user);

    Notification::assertSentTo($user, WelcomeEmail::class);
    Notification::assertSentTo($user, MethodologyEmail::class);
    Notification::assertSentTo($user, ValueBetsEmail::class);
    Notification::assertSentTo($user, MaximizeSubscriptionEmail::class);
    Notification::assertSentTo($user, WeeklyTipsEmail::class);
});

test('completeStep marks step as completed and updates progress', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $progress = $this->service->completeStep($user, 'welcome');

    expect($progress->completed_steps)->toContain('welcome')
        ->and($progress->current_step)->toBe('sport_selection')
        ->and($progress->progress_percentage)->toBe(25);
});

test('completeStep stores step data when provided', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $stepData = ['completed_at' => now()->toISOString()];
    $progress = $this->service->completeStep($user, 'welcome', $stepData);

    expect($progress->step_data)->toHaveKey('welcome')
        ->and($progress->step_data['welcome'])->toBe($stepData);
});

test('completeStep starts onboarding if user has no progress', function () {
    $user = User::factory()->create();

    $progress = $this->service->completeStep($user, 'welcome');

    expect($progress)->toBeInstanceOf(UserOnboardingProgress::class)
        ->and($progress->completed_steps)->toContain('welcome');
});

test('completeStep advances through all steps correctly', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $this->service->completeStep($user, 'welcome');
    $progress = $user->onboardingProgress->fresh();
    expect($progress->current_step)->toBe('sport_selection')
        ->and($progress->progress_percentage)->toBe(25);

    $this->service->completeStep($user, 'sport_selection');
    $progress = $user->onboardingProgress->fresh();
    expect($progress->current_step)->toBe('alert_setup')
        ->and($progress->progress_percentage)->toBe(50);

    $this->service->completeStep($user, 'alert_setup');
    $progress = $user->onboardingProgress->fresh();
    expect($progress->current_step)->toBe('methodology_review')
        ->and($progress->progress_percentage)->toBe(75);

    $this->service->completeStep($user, 'methodology_review');
    $progress = $user->onboardingProgress->fresh();
    expect($progress->current_step)->toBe('complete')
        ->and($progress->progress_percentage)->toBe(100)
        ->and($progress->completed_at)->not->toBeNull();
});

test('savePersonalizationData updates favorite sports', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $data = ['favorite_sports' => ['nfl', 'nba']];
    $progress = $this->service->savePersonalizationData($user, $data);

    expect($progress->favorite_sports)->toBe(['nfl', 'nba']);
});

test('savePersonalizationData updates betting experience', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $data = ['betting_experience' => 'intermediate'];
    $progress = $this->service->savePersonalizationData($user, $data);

    expect($progress->betting_experience)->toBe('intermediate');
});

test('savePersonalizationData updates interests and goals', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $data = [
        'interests' => ['statistics', 'analytics'],
        'goals' => ['make_money', 'learn_strategy'],
    ];
    $progress = $this->service->savePersonalizationData($user, $data);

    expect($progress->interests)->toBe(['statistics', 'analytics'])
        ->and($progress->goals)->toBe(['make_money', 'learn_strategy']);
});

test('savePersonalizationData updates last activity timestamp', function () {
    $user = User::factory()->create();
    $progress = $this->service->startOnboarding($user);
    $originalTime = $progress->last_activity_at;

    sleep(1);

    $this->service->savePersonalizationData($user, ['interests' => ['test']]);
    $updatedProgress = $user->onboardingProgress->fresh();

    expect($updatedProgress->last_activity_at->isAfter($originalTime))->toBeTrue();
});

test('getQuickStartChecklist returns all checklist items', function () {
    $user = User::factory()->create();

    $checklist = $this->service->getQuickStartChecklist($user);

    expect($checklist)->toHaveCount(5)
        ->and($checklist[0])->toHaveKeys(['id', 'title', 'description', 'completed', 'url'])
        ->and($checklist[0]['id'])->toBe('sport_selection')
        ->and($checklist[1]['id'])->toBe('alert_setup')
        ->and($checklist[2]['id'])->toBe('first_bet')
        ->and($checklist[3]['id'])->toBe('methodology_review')
        ->and($checklist[4]['id'])->toBe('performance_check');
});

test('getQuickStartChecklist marks completed steps correctly', function () {
    $user = User::factory()->create();
    $progress = UserOnboardingProgress::create([
        'user_id' => $user->id,
        'current_step' => 'alert_setup',
        'completed_steps' => ['welcome', 'sport_selection'],
        'progress_percentage' => 50,
    ]);

    $checklist = $this->service->getQuickStartChecklist($user);

    expect($checklist[0]['completed'])->toBeTrue() // sport_selection
        ->and($checklist[1]['completed'])->toBeFalse(); // alert_setup not completed yet
});

test('getQuickStartChecklist marks first bet as completed when user has bets', function () {
    $user = User::factory()->create();
    UserBet::factory()->create(['user_id' => $user->id]);

    $checklist = $this->service->getQuickStartChecklist($user);

    $firstBetItem = collect($checklist)->firstWhere('id', 'first_bet');
    expect($firstBetItem['completed'])->toBeTrue();
});

test('getQuickStartChecklist marks performance check as completed with three bets', function () {
    $user = User::factory()->create();
    UserBet::factory()->count(3)->create(['user_id' => $user->id]);

    $checklist = $this->service->getQuickStartChecklist($user);

    $performanceItem = collect($checklist)->firstWhere('id', 'performance_check');
    expect($performanceItem['completed'])->toBeTrue();
});

test('getQuickStartChecklist marks performance check as incomplete with fewer than three bets', function () {
    $user = User::factory()->create();
    UserBet::factory()->count(2)->create(['user_id' => $user->id]);

    $checklist = $this->service->getQuickStartChecklist($user);

    $performanceItem = collect($checklist)->firstWhere('id', 'performance_check');
    expect($performanceItem['completed'])->toBeFalse();
});

test('getOnboardingProgress returns not started when no progress', function () {
    $user = User::factory()->create();

    $progress = $this->service->getOnboardingProgress($user);

    expect($progress)->toBe([
        'started' => false,
        'current_step' => null,
        'progress_percentage' => 0,
        'completed' => false,
    ]);
});

test('getOnboardingProgress returns progress data when started', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $progress = $this->service->getOnboardingProgress($user);

    expect($progress['started'])->toBeTrue()
        ->and($progress['current_step'])->toBe('welcome')
        ->and($progress['progress_percentage'])->toBe(0)
        ->and($progress['completed'])->toBeFalse()
        ->and($progress)->toHaveKeys(['completed_steps', 'started_at', 'is_abandoned']);
});

test('skipOnboarding marks onboarding as complete', function () {
    $user = User::factory()->create();
    $this->service->startOnboarding($user);

    $progress = $this->service->skipOnboarding($user);

    expect($progress->completed_at)->not->toBeNull()
        ->and($progress->progress_percentage)->toBe(100);
});

test('skipOnboarding starts onboarding if not started', function () {
    $user = User::factory()->create();

    $progress = $this->service->skipOnboarding($user);

    expect($progress)->toBeInstanceOf(UserOnboardingProgress::class)
        ->and($progress->completed_at)->not->toBeNull();
});

test('getAvailableSteps returns all onboarding steps', function () {
    $steps = $this->service->getAvailableSteps();

    expect($steps)->toHaveKeys(['welcome', 'sport_selection', 'alert_setup', 'methodology_review'])
        ->and($steps['welcome'])->toBe('Welcome & Overview')
        ->and($steps['sport_selection'])->toBe('Select Your Sports')
        ->and($steps['alert_setup'])->toBe('Set Up Alerts')
        ->and($steps['methodology_review'])->toBe('Review Methodology');
});
