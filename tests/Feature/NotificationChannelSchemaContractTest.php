<?php

use App\Http\Resources\NotificationChannelResource;
use App\Models\NotificationChannel;
use Illuminate\Http\Request;

it('persists retained channel definitions using the actual schema attributes', function () {
    $channel = NotificationChannel::query()->create([
        'name' => 'Synthetic email definition',
        'type' => 'email',
        'is_active' => false,
        'config' => ['synthetic_secret' => 'never-serialize-this'],
    ])->fresh();
    expect($channel->name)->toBe('Synthetic email definition')
        ->and($channel->type)->toBe('email')
        ->and($channel->is_active)->toBeFalse()
        ->and($channel->config)->toBe(['synthetic_secret' => 'never-serialize-this']);
});

it('creates a valid disabled definition without delivery credentials by default', function () {
    $channel = NotificationChannel::factory()->create()->fresh();
    expect($channel->name)->not->toBeEmpty()
        ->and($channel->type)->toBe('email')
        ->and($channel->is_active)->toBeFalse()
        ->and($channel->config)->toBeNull();
});

it('does not serialize channel configuration credentials through model or resource', function () {
    $channel = (new NotificationChannel)->forceFill([
        'id' => 1, 'name' => 'Synthetic Discord definition', 'type' => 'discord',
        'is_active' => true, 'config' => ['webhook_url' => 'https://example.test/synthetic-secret'],
    ]);
    $payload = (new NotificationChannelResource($channel))->resolve(Request::create('/'));
    expect($payload)->toMatchArray(['name' => 'Synthetic Discord definition', 'type' => 'discord', 'is_active' => true, 'configured' => true])
        ->and($payload)->not->toHaveKeys(['config', 'user_id', 'channel', 'enabled'])
        ->and($channel->toArray())->not->toHaveKey('config')
        ->and(json_encode($payload))->not->toContain('synthetic-secret');
});
