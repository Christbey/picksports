<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Controllers\Controller;
use App\Support\TierAccessBypass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

abstract class AbstractSportsApiController extends Controller
{
    protected const MAX_PER_PAGE = 100;

    protected function getPerPage(Request $request, int $default = 15): int
    {
        $perPage = (int) ($request->integer('per_page') ?: $default);
        $perPage = max(1, $perPage);

        return min($perPage, $this->getMaxPerPage());
    }

    protected function getMaxPerPage(): int
    {
        return static::MAX_PER_PAGE;
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
        $tierAccessBypass = app(TierAccessBypass::class);

        if ($tierAccessBypass->shouldBypassTierChecks($user)) {
            return [
                'tier' => null,
                'tier_limit' => null,
                'tier_name' => null,
            ];
        }

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
