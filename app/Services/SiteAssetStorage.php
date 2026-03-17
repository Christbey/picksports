<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class SiteAssetStorage
{
    public function publicUrl(string $key): string
    {
        $asset = $this->assetDefinition($key);
        $path = $this->targetPath($asset['target']);

        if ($this->shouldMirror()) {
            $this->ensureMirrored($asset, $path);
        }

        if ($this->shouldMirror() && Storage::disk($this->disk())->exists($path)) {
            return Storage::disk($this->disk())->url($path);
        }

        return '/'.$asset['source'];
    }

    /**
     * @return array{source:string,target:string,content_type:string}
     */
    private function assetDefinition(string $key): array
    {
        $asset = config("site_assets.files.{$key}");

        if (! is_array($asset) || empty($asset['source']) || empty($asset['target'])) {
            throw new \InvalidArgumentException("Unknown site asset [{$key}].");
        }

        return [
            'source' => (string) $asset['source'],
            'target' => (string) $asset['target'],
            'content_type' => (string) ($asset['content_type'] ?? 'application/octet-stream'),
        ];
    }

    private function ensureMirrored(array $asset, string $path): void
    {
        $disk = Storage::disk($this->disk());

        if ($disk->exists($path)) {
            return;
        }

        $sourcePath = public_path($asset['source']);
        if (! is_file($sourcePath)) {
            return;
        }

        $disk->put($path, file_get_contents($sourcePath), [
            'visibility' => 'public',
            'ContentType' => $asset['content_type'],
        ]);
    }

    private function disk(): string
    {
        return (string) config('site_assets.disk', 's3');
    }

    private function shouldMirror(): bool
    {
        return (bool) config('site_assets.mirror', true);
    }

    private function targetPath(string $target): string
    {
        return trim((string) config('site_assets.directory', 'site-assets'), '/').'/'.ltrim($target, '/');
    }
}
