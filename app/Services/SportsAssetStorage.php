<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $disk = Storage::disk($this->disk());

        if ($disk->exists($path)) {
            return $path;
        }

        try {
            $response = Http::timeout(20)->retry(2, 250)->get($sourceUrl);
        } catch (ConnectionException $e) {
            Log::warning('Sports asset mirror failed to fetch remote asset.', $context + [
                'error' => $e->getMessage(),
            ]);

            return $sourceUrl;
        }

        if (! $response->successful()) {
            Log::warning('Sports asset mirror received non-success response.', $context + [
                'status' => $response->status(),
            ]);

            return $sourceUrl;
        }

        $mimeType = $response->header('Content-Type');
        $options = ['visibility' => 'public'];

        if (is_string($mimeType) && $mimeType !== '') {
            $options['ContentType'] = trim(explode(';', $mimeType)[0]);
        }

        $disk->put($path, $response->body(), $options);

        return $path;
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

        if ($extension !== '' && preg_match('/^[a-z0-9]+$/', $extension) === 1) {
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
