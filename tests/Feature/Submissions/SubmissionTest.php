<?php

use App\Models\Submission;
use App\Models\User;

test('authenticated user can submit feedback', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('submissions.store'), [
            'subject' => 'Sidebar issue',
            'message' => 'The new feature is great, but the button alignment looks off.',
            'page_url' => 'https://picksports.app/dashboard',
        ]);

    $response->assertRedirect();

    $submission = Submission::query()->first();

    expect($submission)->not->toBeNull();
    expect($submission?->user_id)->toBe($user->id);
    expect($submission?->name)->toBe($user->name);
    expect($submission?->email)->toBe($user->email);
    expect($submission?->subject)->toBe('Sidebar issue');
    expect($submission?->status)->toBe('new');
});

test('message is required for feedback submission', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('submissions.store'), [
            'subject' => 'Missing message',
            'message' => '',
        ]);

    $response
        ->assertSessionHasErrors('message')
        ->assertRedirect(route('dashboard'));
});
