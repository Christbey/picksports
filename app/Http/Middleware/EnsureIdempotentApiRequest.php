<?php

namespace App\Http\Middleware;

use App\Exceptions\Api\RollbackIdempotentResponse;
use App\Models\ApiIdempotencyKey;
use App\Services\Api\V2\AtomicIdempotentRoutes;
use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

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

        $connection = (new ApiIdempotencyKey)->getConnection();
        if (! app(AtomicIdempotentRoutes::class)->supports($request->route()?->getName(), $connection->getName())) {
            return $this->error(
                'idempotency_operation_unsupported',
                'This operation is not admitted for atomic idempotent execution.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        // This durable reservation contains no business effects. Only atomic-mode
        // reservations can resume: business changes and their response receipt
        // must subsequently commit in the same connection transaction.
        $record = ApiIdempotencyKey::query()->firstOrCreate(
            ['scope_hash' => $scopeHash],
            [
                'principal_type' => $principalType,
                'principal_id' => $principalId,
                'route_scope' => $routeScope,
                'key_hash' => $keyHash,
                'request_fingerprint' => $requestFingerprint,
                'execution_mode' => ApiIdempotencyKey::ATOMIC_LOCAL,
                'expires_at' => now()->addHours($ttlHours),
            ],
        );

        $reservationId = $record->getKey();
        $owner = ['principal_type' => $principalType, 'principal_id' => $principalId,
            'route_scope' => $routeScope, 'key_hash' => $keyHash, 'scope_hash' => $scopeHash];
        $this->assertRecordIdentity($record, $connection, $reservationId);

        try {
            return $connection->transaction(function () use ($reservationId, $owner, $connection, $request, $requestFingerprint, $ttlHours, $next): Response {
                $record = ApiIdempotencyKey::on($connection->getName())->lockForUpdate()->find($reservationId);
                if ($record === null) {
                    return $this->error('idempotency_reservation_expired', 'The reservation expired; retry with the same key.', Response::HTTP_CONFLICT);
                }
                $this->assertRecordIdentity($record, $connection, $reservationId);
                $this->assertPersistedReceipt($connection, $reservationId, $owner);

                $completed = $record->completed_at !== null && $record->response_status !== null;
                if (! $completed && $record->execution_mode !== ApiIdempotencyKey::ATOMIC_LOCAL) {
                    return $this->error(
                        'idempotency_outcome_unknown',
                        'The earlier operation has an unresolved outcome. Reconcile it before retrying.',
                        Response::HTTP_CONFLICT,
                    );
                }

                // Recycle only completed expired outcomes. Incomplete requests
                // keep their original fingerprint even beyond the replay TTL.
                if ($completed && $record->expires_at->isPast()) {
                    $record->forceFill([
                        'request_fingerprint' => $requestFingerprint,
                        'execution_mode' => ApiIdempotencyKey::ATOMIC_LOCAL,
                        'completed_at' => null,
                        'response_status' => null,
                        'response_headers' => null,
                        'response_body' => null,
                    ]);
                    $completed = false;
                }

                if (! hash_equals($record->request_fingerprint, $requestFingerprint)) {
                    return $this->error('idempotency_key_reused', 'This idempotency key was already used with a different request payload.', Response::HTTP_CONFLICT);
                }
                if ($completed) {
                    return $this->replay($record);
                }

                $response = $next($request);
                if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
                    throw new RollbackIdempotentResponse($response);
                }

                $body = $response->getContent();
                if ($body === false) {
                    throw new RollbackIdempotentResponse($this->error('idempotency_response_unsupported', 'The operation returned an unsupported response.', Response::HTTP_SERVICE_UNAVAILABLE));
                }
                $record->forceFill([
                    'request_fingerprint' => $requestFingerprint,
                    'execution_mode' => ApiIdempotencyKey::ATOMIC_LOCAL,
                    'response_status' => $response->getStatusCode(),
                    'response_headers' => $this->replayableHeaders($response),
                    'response_body' => $body,
                    'completed_at' => now(),
                    'expires_at' => now()->addHours($ttlHours),
                ]);
                // Freeze the requested receipt before saving/updated observers.
                $expected = [...$owner, ...Arr::only($record->getAttributes(), [
                    'request_fingerprint', 'execution_mode', 'response_status', 'response_headers',
                    'response_body', 'completed_at', 'expires_at',
                ])];
                $expiresHeader = $record->expires_at->toIso8601String();
                if ($record->save() !== true) {
                    throw new RuntimeException('Idempotency response receipt save was cancelled.');
                }
                $this->assertRecordIdentity($record, $connection, $reservationId);
                $this->assertPersistedReceipt($connection, $reservationId, $expected);

                $response->headers->set(self::REPLAYED_HEADER, 'false');
                $response->headers->set(self::EXPIRES_HEADER, $expiresHeader);

                return $response;
            }, attempts: 1);
        } catch (RollbackIdempotentResponse $exception) {
            return $exception->response;
        }
    }

    private function assertRecordIdentity(ApiIdempotencyKey $record, Connection $connection, mixed $id): void
    {
        if ($id === null || ! $record->exists || (string) $record->getKey() !== (string) $id
            || $record->getTable() !== 'api_idempotency_keys' || $record->getConnection() !== $connection) {
            throw new RuntimeException('Idempotency reservation identity changed.');
        }
    }

    private function assertPersistedReceipt(Connection $connection, mixed $id, array $expected): void
    {
        $query = $connection->table('api_idempotency_keys')->useWritePdo()->where('id', $id)->lockForUpdate()->select('id');
        foreach ($expected as $name => $value) {
            $column = $connection->getQueryGrammar()->wrap($name);
            $length = $connection->getDriverName() === 'sqlite'
                ? "LENGTH(CAST({$column} AS BLOB))" : "OCTET_LENGTH({$column})";
            // JSON storage may normalize whitespace/key order; bound that one
            // header object separately, then compare its decoded semantics.
            $maximum = $name === 'response_headers' ? 2 * strlen((string) $value) + 64 : strlen((string) $value);
            $query->selectRaw("CASE WHEN {$length} <= ? THEN {$column} ELSE NULL END AS {$column}", [$maximum]);
        }
        $current = $query->first();
        if ($current === null) {
            throw new RuntimeException('Idempotency response receipt disappeared.');
        }
        foreach ($expected as $name => $value) {
            $actual = $current->{$name};
            $matches = $name === 'response_headers'
                ? is_string($actual) && $this->canonicalize(json_decode($actual, true, flags: JSON_THROW_ON_ERROR))
                    === $this->canonicalize(json_decode($value, true, flags: JSON_THROW_ON_ERROR))
                : $actual !== null && (string) $actual === (string) $value;
            if (! $matches) {
                throw new RuntimeException('Idempotency receipt differs from the intended owner or response.');
            }
        }
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
