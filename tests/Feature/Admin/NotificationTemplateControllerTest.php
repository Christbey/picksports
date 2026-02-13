<?php

use App\Models\NotificationTemplate;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('requires authentication to access index', function () {
    $response = $this->get('/admin/notification-templates');

    $response->assertRedirect(route('login'));
});

test('requires admin to access index', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/admin/notification-templates');

    $response->assertForbidden();
});

test('admin can view notification templates index', function () {
    $admin = User::factory()->admin()->create();
    $template = NotificationTemplate::factory()->create();

    $response = $this
        ->actingAs($admin)
        ->get('/admin/notification-templates');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/NotificationTemplates/Index')
        ->has('templates', 1)
        ->where('templates.0.id', $template->id)
    );
});

test('admin can view create template form', function () {
    $admin = User::factory()->admin()->create();

    $response = $this
        ->actingAs($admin)
        ->get('/admin/notification-templates/create');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/NotificationTemplates/Form')
        ->where('template', null)
    );
});

test('admin can create a notification template', function () {
    $admin = User::factory()->admin()->create();

    $response = $this
        ->actingAs($admin)
        ->post('/admin/notification-templates', [
            'name' => 'betting_value_alert',
            'description' => 'Alert for high-value betting opportunities',
            'subject' => 'High-Value Betting Opportunity',
            'email_body' => 'Hi {user_name}, check out this game: {game}',
            'sms_body' => '{game} has {edge}% edge',
            'push_title' => 'New Opportunity',
            'push_body' => '{game} - {edge}% edge',
            'variables' => ['user_name', 'game', 'edge'],
            'active' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.notification-templates.index'));

    expect(NotificationTemplate::where('name', 'betting_value_alert')->exists())->toBeTrue();

    $template = NotificationTemplate::where('name', 'betting_value_alert')->first();
    expect($template->description)->toBe('Alert for high-value betting opportunities');
    expect($template->variables)->toBe(['user_name', 'game', 'edge']);
    expect($template->active)->toBeTrue();
});

test('admin can view edit template form', function () {
    $admin = User::factory()->admin()->create();
    $template = NotificationTemplate::factory()->create();

    $response = $this
        ->actingAs($admin)
        ->get("/admin/notification-templates/{$template->id}/edit");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/NotificationTemplates/Form')
        ->where('template.id', $template->id)
        ->where('template.name', $template->name)
    );
});

test('admin can update a notification template', function () {
    $admin = User::factory()->admin()->create();
    $template = NotificationTemplate::factory()->create([
        'name' => 'old_name',
        'description' => 'Old description',
    ]);

    $response = $this
        ->actingAs($admin)
        ->put("/admin/notification-templates/{$template->id}", [
            'name' => 'updated_name',
            'description' => 'Updated description',
            'subject' => 'Updated Subject',
            'email_body' => 'Updated email body',
            'sms_body' => 'Updated SMS',
            'push_title' => 'Updated Title',
            'push_body' => 'Updated push',
            'variables' => ['new_var'],
            'active' => false,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.notification-templates.index'));

    $template->refresh();

    expect($template->name)->toBe('updated_name');
    expect($template->description)->toBe('Updated description');
    expect($template->active)->toBeFalse();
});

test('admin can delete a notification template', function () {
    $admin = User::factory()->admin()->create();
    $template = NotificationTemplate::factory()->create();

    $response = $this
        ->actingAs($admin)
        ->delete("/admin/notification-templates/{$template->id}");

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.notification-templates.index'));

    expect(NotificationTemplate::find($template->id))->toBeNull();
});

test('name is required when creating template', function () {
    $admin = User::factory()->admin()->create();

    $response = $this
        ->actingAs($admin)
        ->post('/admin/notification-templates', [
            'description' => 'Test description',
            'active' => true,
        ]);

    $response->assertSessionHasErrors('name');
});

test('name must be unique when creating template', function () {
    $admin = User::factory()->admin()->create();
    NotificationTemplate::factory()->create(['name' => 'existing_template']);

    $response = $this
        ->actingAs($admin)
        ->post('/admin/notification-templates', [
            'name' => 'existing_template',
            'active' => true,
        ]);

    $response->assertSessionHasErrors('name');
});

test('name must be unique when updating template except for itself', function () {
    $admin = User::factory()->admin()->create();
    $template1 = NotificationTemplate::factory()->create(['name' => 'template_one']);
    $template2 = NotificationTemplate::factory()->create(['name' => 'template_two']);

    $response = $this
        ->actingAs($admin)
        ->put("/admin/notification-templates/{$template2->id}", [
            'name' => 'template_one',
            'active' => true,
        ]);

    $response->assertSessionHasErrors('name');

    $response = $this
        ->actingAs($admin)
        ->put("/admin/notification-templates/{$template2->id}", [
            'name' => 'template_two',
            'active' => true,
        ]);

    $response->assertSessionHasNoErrors();
});

test('sms body cannot exceed 160 characters', function () {
    $admin = User::factory()->admin()->create();

    $response = $this
        ->actingAs($admin)
        ->post('/admin/notification-templates', [
            'name' => 'test_template',
            'sms_body' => str_repeat('a', 161),
            'active' => true,
        ]);

    $response->assertSessionHasErrors('sms_body');
});

test('variables must be an array', function () {
    $admin = User::factory()->admin()->create();

    $response = $this
        ->actingAs($admin)
        ->post('/admin/notification-templates', [
            'name' => 'test_template',
            'variables' => 'not_an_array',
            'active' => true,
        ]);

    $response->assertSessionHasErrors('variables');
});

test('non-admin cannot create notification template', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/admin/notification-templates', [
            'name' => 'test_template',
            'active' => true,
        ]);

    $response->assertForbidden();
});

test('non-admin cannot update notification template', function () {
    $user = User::factory()->create();
    $template = NotificationTemplate::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put("/admin/notification-templates/{$template->id}", [
            'name' => 'updated_name',
            'active' => true,
        ]);

    $response->assertForbidden();
});

test('non-admin cannot delete notification template', function () {
    $user = User::factory()->create();
    $template = NotificationTemplate::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete("/admin/notification-templates/{$template->id}");

    $response->assertForbidden();
});
