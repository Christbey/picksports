<?php

use App\Models\NBA\Team;
use App\Services\SportsAssetStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function syntheticAssetPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlAAAAABJRU5ErkJggg==');
}

beforeEach(function () {
    config(['sports_assets.disk' => 'public', 'sports_assets.mirror' => true, 'sports_assets.allowed_hosts' => ['assets.example'],
        'sports_assets.maximum_bytes' => 1024, 'sports_assets.maximum_dimension' => 100]);
    Storage::fake('public');
    Http::preventStrayRequests();
});

it('fails closed with an empty exact-host catalog while retaining the source URL', function () {
    config(['sports_assets.allowed_hosts' => []]);
    $url = 'https://assets.example/image.png';
    expect(app(SportsAssetStorage::class)->mirrorTeamLogo($url, 'nba', 'team-1'))->toBe($url);
    Http::assertNothingSent();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects invalid active or mismatched image content without persisting a false path', function (string $body, string $type) {
    $url = 'https://assets.example/image.png';
    Http::fake([$url => Http::response($body, 200, ['Content-Type' => $type])]);
    expect(app(SportsAssetStorage::class)->mirrorTeamLogo($url, 'nba', 'team-1'))->toBe($url)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
    ['<html>not an image</html>', 'image/png'],
    [syntheticAssetPng(), 'text/html'],
    [str_repeat('x', 1025), 'image/png'],
]);

it('stores raster content with verified MIME and a safe extension instead of a provider executable suffix', function () {
    $url = 'https://assets.example/image.php';
    Http::fake([$url => Http::response(syntheticAssetPng(), 200, ['Content-Type' => 'image/png'])]);
    expect(app(SportsAssetStorage::class)->mirrorTeamLogo($url, 'nba', 'team-1'))->toBe('sports/nba/teams/team-1/logo.png');
    Storage::disk('public')->assertExists('sports/nba/teams/team-1/logo.png');
});

it('preserves the actual model source URL and reports zero updates when object storage returns false', function () {
    $url = 'https://assets.example/team.png';
    $team = Team::factory()->create(['espn_id' => '123', 'location' => 'Write', 'name' => 'Failure', 'logo_url' => $url]);
    Http::fake([$url => Http::response(syntheticAssetPng(), 200, ['Content-Type' => 'image/png'])]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->andReturn(false);
    $disk->shouldReceive('put')->once()->with(Mockery::type('string'), syntheticAssetPng(), ['visibility' => 'public', 'ContentType' => 'image/png'])->andReturn(false);
    Storage::shouldReceive('disk')->with('public')->andReturn($disk);
    $this->artisan('team-logos:sync', ['sport' => 'nba'])->expectsOutput('NBA: updated 0 team logo records.')->assertSuccessful();
    expect($team->fresh()->getRawOriginal('logo_url'))->toBe($url);
});
