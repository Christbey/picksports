<?php

namespace App\Services\Api\V2;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class SportPlayerLeaderboardQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function get(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Collection {
        $controllerClass = $this->controllerClass($context);
        $request = $this->requestWithFilters($filters, $user);
        $response = app($controllerClass)->leaderboard($request);

        if ($response instanceof JsonResponse) {
            abort($response->getStatusCode(), (string) ($response->getData(true)['message'] ?? 'Player leaderboard is not available.'));
        }

        if (! $response instanceof AnonymousResourceCollection) {
            abort(404, "Player leaderboard is not available for {$context->slug}.");
        }

        return collect($response->response($request)->getData(true)['data'] ?? [])
            ->map(fn (mixed $row): array => (array) $row)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function requestWithFilters(array $filters, ?Authenticatable $user): Request
    {
        $request = Request::create('/', 'GET', $filters);

        if ($user) {
            $request->setUserResolver(fn (): Authenticatable => $user);
        }

        return $request;
    }

    /**
     * @return class-string
     */
    private function controllerClass(SportContext $context): string
    {
        $controllerClass = "App\\Http\\Controllers\\Api\\{$context->namespace}\\PlayerStatController";

        if (! class_exists($controllerClass)) {
            abort(404, "Player leaderboard is not available for {$context->slug}.");
        }

        return $controllerClass;
    }
}
