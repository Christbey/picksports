<?php

use Illuminate\Support\Facades\Storage;

test('site assets sync command mirrors branded files to configured disk', function () {
    Storage::fake('public');

    config()->set('site_assets.disk', 'public');
    config()->set('site_assets.directory', 'site-assets');
    config()->set('site_assets.mirror', true);

    $this->artisan('site-assets:sync')
        ->expectsOutputToContain('share:')
        ->expectsOutputToContain('icon_512:')
        ->expectsOutputToContain('Site assets synced.')
        ->assertSuccessful();

    Storage::disk('public')->assertExists('site-assets/branding/picksports-share.png');
    Storage::disk('public')->assertExists('site-assets/branding/icon-512.png');
    Storage::disk('public')->assertExists('site-assets/branding/icon-192.png');
    Storage::disk('public')->assertExists('site-assets/branding/apple-touch-icon.png');
});
