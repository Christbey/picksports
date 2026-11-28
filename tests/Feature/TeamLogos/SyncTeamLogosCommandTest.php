<?php

use App\Models\NBA\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

test('team logo sync command mirrors existing remote logos into configured storage', function () {
    config()->set('sports_assets.disk', 'public');
    config()->set('sports_assets.directory', 'sports');
    config()->set('sports_assets.mirror', true);
    config()->set('sports_assets.allowed_hosts', ['example.com']);

    Storage::fake('public');

    $team = Team::factory()->create([
        'espn_id' => '1',
        'location' => 'Atlanta',
        'name' => 'Hawks',
        'logo_url' => 'https://example.com/atl.png',
    ]);

    Http::fake([
        'https://example.com/atl.png' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlAAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png']),
    ]);

    artisan('team-logos:sync nba')->assertSuccessful();

    $team->refresh();

    expect($team->getRawOriginal('logo_url'))->toBe('sports/nba/teams/atlanta-hawks-1/logo.png')
        ->and($team->logo_url)->toContain('/storage/sports/nba/teams/atlanta-hawks-1/logo.png');

    Storage::disk('public')->assertExists('sports/nba/teams/atlanta-hawks-1/logo.png');
});
