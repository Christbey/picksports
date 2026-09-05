<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotentApiRequest
{
    public const KEY_HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotency-Replayed';

    public const EXPIRES_HEADER = 'Idempotency-Key-Expires-At';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::KEY_HEADER);

        if ($key === null) {
            return $next($request);
        }

        if (! is_string($key) || preg_match('/\A[\x21-\x7E]{1,255}\z/D', $key) !== 1) {
            return $this->error(
                'invalid_idempotency_key',
                'The Idempotency-Key header must contain 1 to 255 visible ASCII characters without spaces.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $principal = $request->user();

        if ($principal === null) {
            return $this->error(
                'idempotency_requires_authentication',
                'An authenticated principal is required when using an idempotency key.',
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $principalType = $principal->getMorphClass();
        $principalId = (string) $principal->getAuthIdentifier();
        $routeScope = $request->route()?->getName() ?? $request->method().':'.$request->path();
        $keyHash = hash('sha256', $key);
        $scopeHash = hash('sha256', implode("\0", [$principalType, $principalId, $routeScope, $keyHash]));
        $requestFingerprint = $this->fingerprint($request);
        $ttlHours = max(1, (int) config('api.v2.idempotency.ttl_hours', 24));

        $record = $this->resolveRecord(
            $principalType,
            $principalId,
            $routeScope,
            $keyHash,
            $scopeHash,
            $requestFingerprint,
            $ttlHours,
        );

        if (! $record->wasRecentlyCreated) {
            if (! hash_equals($record->request_fingerprint, $requestFingerprint)) {
                return $this->error(
                    'idempotency_key_reused',
                    'This idempotency key was already used with a different request payload.',
                    Response::HTTP_CONFLICT,
                );
            }

            if ($record->completed_at === null || $record->response_status === null) {
                return $this->error(
                    'idempotency_request_in_progress',
                    'A request with this idempotency key is already in progress.',
                    Response::HTTP_CONFLICT,
                );
            }

            return $this->replay($record);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        if ($response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            $record->delete();

            return $response;
        }

        $record->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_headers' => $this->replayableHeaders($response),
            'response_body' => $response->getContent(),
            'completed_at' => now(),
        ])->save();

        $response->headers->set(self::REPLAYED_HEADER, 'false');
        $response->headers->set(self::EXPIRES_HEADER, $record->expires_at->toIso8601String());

        return $response;
    }

    private function resolveRecord(
        string $principalType,
        string $principalId,
        string $routeScope,
        string $keyHash,
        string $scopeHash,
        string $requestFingerprint,
        int $ttlHours,
    ): ApiIdempotencyKey {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $record = ApiIdempotencyKey::query()->firstOrCreate(
                ['scope_hash' => $scopeHash],
                [
                    'principal_type' => $principalType,
                    'principal_id' => $principalId,
                    'route_scope' => $routeScope,
                    'key_hash' => $keyHash,
                    'request_fingerprint' => $requestFingerprint,
                    'expires_at' => now()->addHours($ttlHours),
                ],
            );

            if ($record->wasRecentlyCreated || $record->expires_at->isFuture()) {
                return $record;
            }

            ApiIdempotencyKey::query()
                ->whereKey($record->getKey())
                ->where('expires_at', '<=', now())
                ->delete();
        }

        return ApiIdempotencyKey::query()->where('scope_hash', $scopeHash)->firstOrFail();
    }

    private function fingerprint(Request $request): string
    {
        $payload = $this->canonicalize($request->all());
        $routeParameters = $this->canonicalize($request->route()?->parameters() ?? []);

        return hash('sha256', json_encode([
            'method' => $request->method(),
            'route' => $request->route()?->uri() ?? $request->path(),
            'route_parameters' => $routeParameters,
            'payload' => $payload,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof UrlRoutable) {
            return (string) $value->getRouteKey();
        }

        if (! is_array($value)) {
            return $value;
        }

        if (Arr::isAssoc($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /**
     * @return array<string, string>
     */
    private function replayableHeaders(Response $response): array
    {
        return collect(['Content-Type', 'Location'])
            ->mapWithKeys(function (string $header) use ($response): array {
                $value = $response->headers->get($header);

                return $value === null ? [] : [$header => $value];
            })
            ->all();
    }

    private function replay(ApiIdempotencyKey $record): Response
    {
        $response = new Response(
            $record->response_body ?? '',
            $record->response_status,
            $record->response_headers ?? [],
        );

        $response->headers->set(self::REPLAYED_HEADER, 'true');
        $response->headers->set(self::EXPIRES_HEADER, $record->expires_at->toIso8601String());

        return $response;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'message' => $message,
        ], $status);
    }
}
