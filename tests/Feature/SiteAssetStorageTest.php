<?php

use App\Services\SiteAssetStorage;
use Illuminate\Support\Facades\Storage;

test('site asset storage mirrors branded assets to configured disk and returns public urls', function () {
    Storage::fake('public');

    config()->set('site_assets.disk', 'public');
    config()->set('site_assets.directory', 'site-assets');
    config()->set('site_assets.mirror', true);

    $service = app(SiteAssetStorage::class);

    $url = $service->publicUrl('share');

    expect($url)->toBe('/storage/site-assets/branding/picksports-share.png');
    Storage::disk('public')->assertExists('site-assets/branding/picksports-share.png');
});
