<?php

namespace App\Models\Concerns;

use App\Services\SportsAssetStorage;

trait ResolvesTeamLogoUrls
{
    public function getLogoUrlAttribute($value): ?string
    {
        return app(SportsAssetStorage::class)->publicUrl($value);
    }

    public function getLogoAttribute($value): ?string
    {
        $rawValue = $value ?? $this->getRawOriginal('logo_url');

        return app(SportsAssetStorage::class)->publicUrl($rawValue);
    }
}
