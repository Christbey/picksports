<?php

use App\Models\Submission;
use App\Models\User;

test('admin can view users overview with user and submission metrics', function () {
    $this->withoutVite();

    $admin = User::factory()->admin()->create([
        'created_at' => now()->subDays(3),
    ]);

    User::factory()->create([
        'created_at' => now(),
    ]);

    User::factory()->create([
        'created_at' => now()->subDay(),
    ]);

    Submission::query()->create([
        'user_id' => $admin->id,
        'name' => $admin->name,
        'email' => $admin->email,
        'subject' => 'Today submission',
        'message' => 'Feedback from today.',
        'page_url' => 'https://picksports.app/dashboard',
        'status' => 'new',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $olderSubmission = Submission::query()->create([
        'user_id' => $admin->id,
        'name' => $admin->name,
        'email' => $admin->email,
        'subject' => 'Older submission',
        'message' => 'Feedback from yesterday.',
        'page_url' => 'https://picksports.app/nfl/predictions',
        'status' => 'new',
    ]);

    Submission::query()
        ->whereKey($olderSubmission->id)
        ->update([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

    $response = $this->actingAs($admin)->get(route('admin.users'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Users')
        ->where('stats.total_users', 3)
        ->where('stats.new_users_today', 1)
        ->where('stats.total_submissions', 2)
        ->where('stats.submissions_today', 1)
        ->has('users.data', 3)
        ->has('submissions.data', 2)
    );
});
