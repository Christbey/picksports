<?php

use Illuminate\Support\Carbon;
use App\Models\Submission;
use App\Models\User;

test('admin can view users overview with user and submission metrics', function () {
    $this->withoutVite();

    $admin = User::factory()->admin()->create([
        'created_at' => now()->subDays(3),
        'last_active_at' => now(),
    ]);

    User::factory()->create([
        'created_at' => now(),
        'last_active_at' => now()->subMinutes(2),
    ]);

    User::factory()->create([
        'created_at' => now()->subDay(),
        'last_active_at' => now()->subMinutes(10),
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
        ->where('stats.active_users_now', 2)
        ->where('stats.total_submissions', 2)
        ->where('stats.submissions_today', 1)
        ->has('users.data', 3)
        ->where('users.data.0.is_active', true)
        ->where('users.data.0.last_active_at', $admin->fresh()->last_active_at?->toISOString())
        ->where('filters.status', 'all')
        ->where('filters.sort', 'created_desc')
        ->has('submissions.data', 2)
    );
});

test('authenticated requests update the user last active timestamp', function () {
    $this->withoutVite();

    $admin = User::factory()->admin()->create([
        'last_active_at' => null,
    ]);

    expect($admin->last_active_at)->toBeNull();

    $this->actingAs($admin)->get(route('admin.users'))->assertOk();

    expect($admin->fresh()->last_active_at)->not()->toBeNull();
});

test('admin can filter users by active status and sort by last activity', function () {
    $this->withoutVite();

    Carbon::setTestNow(now());

    $admin = User::factory()->admin()->create([
        'name' => 'Admin User',
        'last_active_at' => now(),
    ]);

    $mostRecent = User::factory()->create([
        'name' => 'Recent User',
        'last_active_at' => now()->subMinute(),
    ]);

    User::factory()->create([
        'name' => 'Offline User',
        'last_active_at' => now()->subMinutes(12),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.users', [
        'status' => 'active',
        'sort' => 'last_active_desc',
    ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Users')
        ->where('filters.status', 'active')
        ->where('filters.sort', 'last_active_desc')
        ->has('users.data', 2)
        ->where('users.data.0.id', $admin->id)
        ->where('users.data.1.id', $mostRecent->id)
    );

    Carbon::setTestNow();
});

test('heartbeat endpoint refreshes the user last active timestamp', function () {
    $user = User::factory()->create([
        'last_active_at' => now()->subMinutes(30),
    ]);

    $previousLastActiveAt = $user->last_active_at;

    $this->actingAs($user)
        ->post(route('app.heartbeat'))
        ->assertNoContent();

    expect($user->fresh()->last_active_at?->gt($previousLastActiveAt))->toBeTrue();
});
