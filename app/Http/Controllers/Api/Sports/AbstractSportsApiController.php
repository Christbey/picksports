<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

abstract class AbstractSportsApiController extends Controller
{
    protected function getPerPage(Request $request, int $default = 15): int
    {
        return (int) ($request->integer('per_page') ?: $default);
    }

    protected function requireNumericId(mixed $value): int
    {
        if (! is_numeric($value) || (string) (int) $value !== (string) $value) {
            abort(404);
        }

        return (int) $value;
    }

    /**
     * @return array{tier:mixed,tier_limit:int|null,tier_name:string|null}
     */
    protected function resolveTierMetadata(string $limitMethod): array
    {
        $user = auth()->user();
        $tier = $user?->subscriptionTier();
        $tierLimit = $tier && method_exists($tier, $limitMethod)
            ? $tier->{$limitMethod}()
            : null;

        return [
            'tier' => $tier,
            'tier_limit' => $tierLimit,
            'tier_name' => $tier?->name,
        ];
    }

    protected function withTierMetadata(AnonymousResourceCollection $collection, array $tierMetadata): AnonymousResourceCollection
    {
        return $collection->additional([
            'tier_limit' => $tierMetadata['tier_limit'],
            'tier_name' => $tierMetadata['tier_name'],
        ]);
    }

    /**
     * @return array{metadata:array{tier:mixed,tier_limit:int|null,tier_name:string|null},limit:int|null}
     */
    protected function resolveTierContext(string $limitMethod): array
    {
        $metadata = $this->resolveTierMetadata($limitMethod);

        return [
            'metadata' => $metadata,
            'limit' => $metadata['tier_limit'],
        ];
    }
}
