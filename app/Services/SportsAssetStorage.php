<?php

namespace App\Services;

use App\Services\Http\ApprovedHostGet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SportsAssetStorage
{
    public function publicUrl(?string $value): ?string
    {
        $value = $this->normalizeValue($value);

        if ($value === null) {
            return null;
        }

        if ($this->isAbsoluteUrl($value)) {
            return $value;
        }

        return Storage::disk($this->disk())->url($value);
    }

    public function mirrorTeamLogo(?string $sourceUrl, string $sport, string $teamIdentifier): ?string
    {
        return $this->mirrorRemoteAsset(
            $sourceUrl,
            $this->teamLogoPath($sourceUrl, $sport, $teamIdentifier),
            [
                'source_url' => $sourceUrl,
                'sport' => $sport,
                'team_identifier' => $teamIdentifier,
                'asset_type' => 'team_logo',
            ]
        );
    }

    public function mirrorPlayerHeadshot(?string $sourceUrl, string $sport, string $teamIdentifier, string $playerIdentifier): ?string
    {
        return $this->mirrorRemoteAsset(
            $sourceUrl,
            $this->playerHeadshotPath($sourceUrl, $sport, $teamIdentifier, $playerIdentifier),
            [
                'source_url' => $sourceUrl,
                'sport' => $sport,
                'team_identifier' => $teamIdentifier,
                'player_identifier' => $playerIdentifier,
                'asset_type' => 'player_headshot',
            ]
        );
    }

    public function teamLogoPath(?string $sourceUrl, string $sport, string $teamIdentifier): ?string
    {
        $sourceUrl = $this->normalizeValue($sourceUrl);

        if ($sourceUrl === null) {
            return null;
        }

        return sprintf(
            '%s/%s/teams/%s/logo.%s',
            $this->baseDirectory(),
            $this->normalizeSegment($sport),
            $this->slugIdSegment($teamIdentifier),
            $this->extensionFromUrl($sourceUrl)
        );
    }

    public function playerHeadshotPath(?string $sourceUrl, string $sport, string $teamIdentifier, string $playerIdentifier): ?string
    {
        $sourceUrl = $this->normalizeValue($sourceUrl);

        if ($sourceUrl === null) {
            return null;
        }

        return sprintf(
            '%s/%s/teams/%s/players/%s/headshot.%s',
            $this->baseDirectory(),
            $this->normalizeSegment($sport),
            $this->slugIdSegment($teamIdentifier),
            $this->slugIdSegment($playerIdentifier),
            $this->extensionFromUrl($sourceUrl)
        );
    }

    protected function mirrorRemoteAsset(?string $sourceUrl, ?string $path, array $context = []): ?string
    {
        $sourceUrl = $this->normalizeValue($sourceUrl);

        if ($sourceUrl === null || $path === null) {
            return null;
        }

        if (! $this->shouldMirror()) {
            return $sourceUrl;
        }

        if (! $this->isAbsoluteUrl($sourceUrl)) {
            return $sourceUrl;
        }

        try {
            $transport = app(ApprovedHostGet::class);
            $hosts = (array) config('sports_assets.allowed_hosts', []);
            $transport->normalize($sourceUrl, $hosts);
            $disk = Storage::disk($this->disk());
            if ($disk->exists($path)) {
                return $path;
            }
            $response = $transport->get($sourceUrl, $hosts, (int) config('sports_assets.maximum_bytes', 5 * 1024 * 1024));
            if (! $response->successful()) {
                throw new RuntimeException('Asset response was unsuccessful.');
            }
            $body = $response->body();
            $image = @getimagesizefromstring($body);
            $types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];
            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $maximumDimension = min(8192, max(1, (int) config('sports_assets.maximum_dimension', 4096)));
            if (! is_array($image) || ! isset($types[$image[2]]) || $types[$image[2]] !== $mime
                || $image[0] < 1 || $image[1] < 1 || $image[0] > $maximumDimension || $image[1] > $maximumDimension) {
                throw new RuntimeException('Asset is not an admitted raster image.');
            }
            if (! $disk->put($path, $body, ['visibility' => 'public', 'ContentType' => $mime])) {
                throw new RuntimeException('Asset storage did not accept the write.');
            }

            return $path;
        } catch (Throwable $exception) {
            // Provider URLs/errors can contain credentials or opaque query tokens.
            Log::warning('Sports asset mirror refused or failed.', ['sport' => $context['sport'] ?? null,
                'asset_type' => $context['asset_type'] ?? null, 'exception_class' => $exception::class]);

            return $sourceUrl;
        }
    }

    protected function disk(): string
    {
        return (string) config('sports_assets.disk', 's3');
    }

    protected function shouldMirror(): bool
    {
        return (bool) config('sports_assets.mirror', false);
    }

    protected function baseDirectory(): string
    {
        return trim((string) config('sports_assets.directory', 'sports'), '/');
    }

    protected function extensionFromUrl(string $sourceUrl): string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return $extension;
        }

        return 'png';
    }

    protected function isAbsoluteUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function normalizeValue(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    protected function normalizeSegment(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();

        return $normalized !== '' ? $normalized : 'unknown';
    }

    protected function slugIdSegment(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'unknown';
        }

        if (preg_match('/^(.*?)-(\d+)$/', $trimmed, $matches) === 1) {
            $slug = $this->normalizeSegment($matches[1]);
            $id = $matches[2];

            return $slug === 'unknown' ? $id : "{$slug}-{$id}";
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return $trimmed;
        }

        return $this->normalizeSegment($trimmed);
    }
}
