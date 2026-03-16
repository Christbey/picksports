<?php

namespace App\Models\Concerns;

use App\Services\SportsAssetStorage;

trait ResolvesPlayerHeadshotUrls
{
    public function getHeadshotUrlAttribute($value): ?string
    {
        return app(SportsAssetStorage::class)->publicUrl($value);
    }

    public function getHeadshotAttribute($value): ?string
    {
        $rawValue = $value ?? $this->getRawOriginal('headshot_url');

        return app(SportsAssetStorage::class)->publicUrl($rawValue);
    }
}
