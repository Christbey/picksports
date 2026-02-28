<?php

namespace App\Support;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourcePayload
{
    /**
     * @return array<int|string, mixed>
     */
    public static function from(JsonResource|AnonymousResourceCollection $resource): array
    {
        $resolved = $resource->resolve();

        return $resolved['data'] ?? $resolved;
    }
}
