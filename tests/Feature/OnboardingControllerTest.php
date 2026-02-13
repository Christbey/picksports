<?php

use App\Models\User;
use App\Models\UserBet;
use App\Models\UserOnboardingProgress;

test('index requires authentication', function () {
    $response = $this->getJson('/api/v1/onboarding');

    $response->assertUnauthorized();
});

test('index returns onboarding progress for authenticated user', function () {
    $user = User::factory()->create();
    UserOnboardingProgress::create([
        'user_id' => $user->id,
        'current_step' => 'sport_selection',
        'completed_steps' => ['welcome'],
        'progress_percentage' => 25,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/onboarding');

    $response->assertOk()
        ->assertJson([
            'started' => true,
            'current_step' => 'sport_selection',
            'progress_percentage' => 25,
            'completed' => false,
        ]);
});

test('index returns not started when user has no progress', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/onboarding');

    $response->assertOk()
        ->assertJson([
            'started' => false,
            'current_step' => null,
            'progress_percentage' => 0,
            'completed' => false,
        ]);
});

test('checklist requires authentication', function () {
    $response = $this->getJson('/api/v1/onboarding/checklist');

    $response->assertUnauthorized();
});

test('checklist returns checklist items for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/onboarding/checklist');

    $response->assertOk()
        ->assertJsonStructure([
            'checklist' => [
                '*' => ['id', 'title', 'description', 'completed', 'url'],
            ],
            'total_items',
            'completed_items',
        ])
        ->assertJson([
            'total_items' => 5,
            'completed_items' => 0,
        ]);
});

test('checklist counts completed items correctly', function () {
    $user = User::factory()->create();
    UserOnboardingProgress::create([
        'user_id' => $user->id,
        'current_step' => 'alert_setup',
        'completed_steps' => ['welcome', 'sport_selection'],
        'progress_percentage' => 50,
    ]);
    UserBet::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/onboarding/checklist');

    $response->assertOk()
        ->assertJson([
            'total_items' => 5,
            'completed_items' => 2, // sport_selection + first_bet
        ]);
});

test('steps requires authentication', function () {
    $response = $this->getJson('/api/v1/onboarding/steps');

    $response->assertUnauthorized();
});

test('steps returns available onboarding steps', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/onboarding/steps');

    $response->assertOk()
        ->assertJsonStructure(['steps'])
        ->assertJson([
            'steps' => [
                'welcome' => 'Welcome & Overview',
                'sport_selection' => 'Select Your Sports',
                'alert_setup' => 'Set Up Alerts',
                'methodology_review' => 'Review Methodology',
            ],
        ]);
});

test('completeStep requires authentication', function () {
    $response = $this->postJson('/api/v1/onboarding/steps/complete', [
        'step' => 'welcome',
    ]);

    $response->assertUnauthorized();
});

test('completeStep validates step is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/steps/complete', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['step']);
});

test('completeStep validates step is valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/steps/complete', [
        'step' => 'invalid_step',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['step']);
});

test('completeStep marks step as completed', function () {
    $user = User::factory()->create();
    UserOnboardingProgress::create([
        'user_id' => $user->id,
        'current_step' => 'welcome',
        'completed_steps' => [],
        'progress_percentage' => 0,
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/steps/complete', [
        'step' => 'welcome',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'progress'])
        ->assertJson([
            'message' => 'Step completed successfully',
            'progress' => [
                'current_step' => 'sport_selection',
                'progress_percentage' => 25,
            ],
        ]);

    $this->assertDatabaseHas('user_onboarding_progress', [
        'user_id' => $user->id,
        'current_step' => 'sport_selection',
    ]);
});

test('completeStep stores optional step data', function () {
    $user = User::factory()->create();
    UserOnboardingProgress::create([
        'user_id' => $user->id,
        'current_step' => 'welcome',
        'completed_steps' => [],
        'progress_percentage' => 0,
    ]);

    $stepData = ['setting' => 'value'];
    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/steps/complete', [
        'step' => 'welcome',
        'data' => $stepData,
    ]);

    $response->assertOk();

    $progress = $user->onboardingProgress->fresh();
    expect($progress->step_data['welcome'])->toBe($stepData);
});

test('savePersonalization requires authentication', function () {
    $response = $this->postJson('/api/v1/onboarding/personalization', [
        'favorite_sports' => ['nfl'],
    ]);

    $response->assertUnauthorized();
});

test('savePersonalization validates favorite sports are valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/personalization', [
        'favorite_sports' => ['invalid_sport'],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['favorite_sports.0']);
});

test('savePersonalization validates betting experience is valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/personalization', [
        'betting_experience' => 'invalid_level',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['betting_experience']);
});

test('savePersonalization saves valid data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/personalization', [
        'favorite_sports' => ['nfl', 'nba'],
        'betting_experience' => 'intermediate',
        'interests' => ['statistics', 'analytics'],
        'goals' => ['make_money'],
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'progress'])
        ->assertJson([
            'message' => 'Personalization data saved successfully',
        ]);

    $this->assertDatabaseHas('user_onboarding_progress', [
        'user_id' => $user->id,
    ]);

    $progress = $user->fresh()->onboardingProgress;
    expect($progress->favorite_sports)->toBe(['nfl', 'nba'])
        ->and($progress->betting_experience)->toBe('intermediate')
        ->and($progress->interests)->toBe(['statistics', 'analytics'])
        ->and($progress->goals)->toBe(['make_money']);
});

test('savePersonalization accepts all valid sports', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/personalization', [
        'favorite_sports' => ['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'],
    ]);

    $response->assertOk();

    $progress = $user->fresh()->onboardingProgress;
    expect($progress->favorite_sports)->toBe(['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba']);
});

test('savePersonalization accepts all valid betting experience levels', function () {
    foreach (['beginner', 'intermediate', 'advanced'] as $level) {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/onboarding/personalization', [
            'betting_experience' => $level,
        ]);

        $response->assertOk();

        $progress = $user->fresh()->onboardingProgress;
        expect($progress->betting_experience)->toBe($level);
    }
});

test('skip requires authentication', function () {
    $response = $this->postJson('/api/v1/onboarding/skip');

    $response->assertUnauthorized();
});

test('skip marks onboarding as complete', function () {
    $user = User::factory()->create();
    UserOnboardingProgress::create([
        'user_id' => $user->id,
        'current_step' => 'sport_selection',
        'completed_steps' => ['welcome'],
        'progress_percentage' => 25,
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/onboarding/skip');

    $response->assertOk()
        ->assertJsonStructure(['message', 'progress'])
        ->assertJson([
            'message' => 'Onboarding skipped successfully',
            'progress' => [
                'progress_percentage' => 100,
            ],
        ]);

    $progress = $user->onboardingProgress->fresh();
    expect($progress->completed_at)->not->toBeNull()
        ->and($progress->progress_percentage)->toBe(100);
});
