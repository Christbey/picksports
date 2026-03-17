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

test('site asset storage falls back to local public asset when s3 bucket is not configured', function () {
    config()->set('site_assets.disk', 's3');
    config()->set('site_assets.mirror', true);
    config()->set('filesystems.disks.s3.bucket', '');

    $service = app(SiteAssetStorage::class);

    expect($service->publicUrl('share'))->toBe('/picksports-share.png');
});
