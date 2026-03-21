<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Throwable;

class SiteAssetStorage
{
    /**
     * @return array<string, string>
     */
    public function syncAll(): array
    {
        $synced = [];

        foreach (array_keys((array) config('site_assets.files', [])) as $key) {
            $synced[$key] = $this->publicUrl((string) $key);
        }

        return $synced;
    }

    public function publicUrl(string $key): string
    {
        $asset = $this->assetDefinition($key);
        $path = $this->targetPath($asset['target']);

        if ($this->shouldMirror() && $this->storageIsAvailable()) {
            $this->ensureMirrored($asset, $path);
        }

        if ($this->shouldMirror() && $this->storageIsAvailable()) {
            try {
                $disk = Storage::disk($this->disk());

                if ($disk->exists($path)) {
                    return $disk->url($path);
                }
            } catch (Throwable) {
                return '/'.$asset['source'];
            }
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
        try {
            $disk = Storage::disk($this->disk());

            if ($disk->exists($path)) {
                return;
            }

            $sourcePath = public_path($asset['source']);
            if (! is_file($sourcePath)) {
                return;
            }

            $contents = file_get_contents($sourcePath);
            if ($contents === false) {
                return;
            }

            $disk->put($path, $contents, [
                'visibility' => 'public',
                'ContentType' => $asset['content_type'],
            ]);
        } catch (Throwable) {
            return;
        }
    }

    private function disk(): string
    {
        return (string) config('site_assets.disk', 's3');
    }

    private function shouldMirror(): bool
    {
        return (bool) config('site_assets.mirror', true);
    }

    private function storageIsAvailable(): bool
    {
        $disk = $this->disk();

        if ($disk === 's3') {
            $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));

            return $bucket !== '';
        }

        return true;
    }

    private function targetPath(string $target): string
    {
        return trim((string) config('site_assets.directory', 'site-assets'), '/').'/'.ltrim($target, '/');
    }
}
