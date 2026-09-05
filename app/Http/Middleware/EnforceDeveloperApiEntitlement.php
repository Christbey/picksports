<?php

namespace App\Http\Middleware;

use App\Exceptions\DeveloperApiQuotaExceeded;
use App\Models\DeveloperApiCredential;
use App\Services\DeveloperPlatform\DeveloperApiQuotaConsumer;
use App\Services\DeveloperPlatform\DeveloperEntitlementResolver;
use App\Support\Api\ApiV2ErrorResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnforceDeveloperApiEntitlement
{
    public function __construct(
        private readonly DeveloperEntitlementResolver $entitlementResolver,
        private readonly DeveloperApiQuotaConsumer $quotaConsumer,
        private readonly ApiV2ErrorResponse $errorResponse,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
        string $productCode,
        string $scope,
        string $operation,
    ): Response {
        $credential = $request->user('developer-api');

        if (! $credential instanceof DeveloperApiCredential) {
            return $this->error('developer_credential_required', 'A developer API credential is required.', 401);
        }

        if (! $credential->hasScope($scope)) {
            return $this->error('developer_scope_denied', 'The credential does not grant the required scope.', 403);
        }

        $policy = $this->entitlementResolver->resolve($credential->organization, $productCode, $scope);

        if ($policy === null) {
            return $this->error('developer_entitlement_denied', 'No active product entitlement grants this operation.', 403);
        }

        try {
            $consumption = $this->quotaConsumer->consume(
                credential: $credential,
                policy: $policy,
                operation: $operation,
                scope: $scope,
                requestId: $this->errorResponse->requestId($request),
            );
        } catch (DeveloperApiQuotaExceeded $exception) {
            return $this->withQuotaHeaders(
                $this->error('developer_quota_exceeded', $exception->getMessage(), 429),
                $exception->limit,
                0,
                $exception->resetsAt->timestamp,
            );
        } catch (LogicException) {
            return $this->error('developer_entitlement_invalid', 'The developer entitlement is not configured for this operation.', 503);
        }

        $request->attributes->set('developer_api_credential', $credential);
        $request->attributes->set('developer_entitlement_policy', $policy);
        $request->attributes->set('developer_usage_record', $consumption->usageRecord);

        return $this->withQuotaHeaders(
            $next($request),
            $consumption->limit,
            $consumption->remaining,
            $consumption->resetsAt->timestamp,
        );
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
            'message' => $message,
        ], $status);
    }

    private function withQuotaHeaders(Response $response, int $limit, int $remaining, int $resetTimestamp): Response
    {
        $window = max(0, $resetTimestamp - now()->timestamp);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) $resetTimestamp);
        $response->headers->set('RateLimit-Policy', "{$limit};w={$window}");

        if ($response->getStatusCode() === 429) {
            $response->headers->set('Retry-After', (string) $window);
        }

        return $response;
    }
}
