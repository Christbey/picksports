<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiV2ErrorResponse
{
    public const REQUEST_ID_ATTRIBUTE = 'api_v2_request_id';

    public const REQUEST_ID_HEADER = 'X-Request-ID';

    public function appliesTo(Request $request): bool
    {
        return $request->is('api/v2') || $request->is('api/v2/*');
    }

    public function requestId(Request $request): string
    {
        $resolvedRequestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (is_string($resolvedRequestId) && $resolvedRequestId !== '') {
            return $resolvedRequestId;
        }

        $providedRequestId = $request->headers->get(self::REQUEST_ID_HEADER);
        $requestId = is_string($providedRequestId) && $this->isValidRequestId($providedRequestId)
            ? $providedRequestId
            : (string) Str::ulid();

        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);

        return $requestId;
    }

    /**
     * @param  array<string, array<int, string>>  $fields
     */
    public function make(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $fields = [],
    ): JsonResponse {
        $requestId = $this->requestId($request);
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $payload = [
            'error' => $error,
            'request_id' => $requestId,
            'message' => $message,
        ];

        if ($fields !== []) {
            $payload['errors'] = $fields;
        }

        return response()->json($payload, $status, [self::REQUEST_ID_HEADER => $requestId]);
    }

    public function normalize(Request $request, Response $response): Response
    {
        if (! $this->appliesTo($request)) {
            return $response;
        }

        $requestId = $this->requestId($request);
        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);

        if ($response->getStatusCode() < 400) {
            return $response;
        }

        $payload = $this->payload($response);

        if ($this->isStandardEnvelope($payload)) {
            if ($response->getStatusCode() >= 500) {
                $payload['error']['code'] = $this->code($response->getStatusCode());
                $payload['error']['message'] = 'An unexpected error occurred.';
                $payload['message'] = 'An unexpected error occurred.';
                unset($payload['error']['fields'], $payload['errors'], $payload['exception'], $payload['trace']);
            }

            $payload['request_id'] = $requestId;
            $payload['error']['request_id'] = $requestId;

            return $this->jsonResponse($response, $payload);
        }

        $status = $response->getStatusCode();
        $message = $this->message($payload, $status);
        $fields = $this->fieldErrors($payload);
        $error = [
            'code' => $this->code($status),
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $envelope = [
            'error' => $error,
            'request_id' => $requestId,

            // Preserve Laravel's existing error keys while clients migrate to the v2 envelope.
            'message' => $message,
        ];

        if ($fields !== []) {
            $envelope['errors'] = $fields;
        }

        return $this->jsonResponse($response, $envelope);
    }

    private function isValidRequestId(string $requestId): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $requestId) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Response $response): array
    {
        if ($response instanceof JsonResponse) {
            $payload = $response->getData(true);

            return is_array($payload) ? $payload : [];
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [];
        }

        $payload = json_decode((string) $response->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isStandardEnvelope(array $payload): bool
    {
        return isset($payload['error'])
            && is_array($payload['error'])
            && is_string($payload['error']['code'] ?? null)
            && is_string($payload['error']['message'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function message(array $payload, int $status): string
    {
        if ($status >= 500) {
            return 'An unexpected error occurred.';
        }

        if (is_string($payload['message'] ?? null) && $payload['message'] !== '') {
            return $payload['message'];
        }

        if (is_string($payload['error'] ?? null) && $payload['error'] !== '') {
            return $payload['error'];
        }

        return Response::$statusTexts[$status] ?? 'Request failed.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, string>>
     */
    private function fieldErrors(array $payload): array
    {
        $errors = $payload['errors'] ?? null;

        if (! is_array($errors)) {
            return [];
        }

        return collect($errors)
            ->filter(fn (mixed $messages, mixed $field): bool => is_string($field) && is_array($messages))
            ->map(fn (array $messages): array => array_values(array_filter(
                $messages,
                fn (mixed $message): bool => is_string($message),
            )))
            ->filter()
            ->all();
    }

    private function code(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            419 => 'csrf_token_mismatch',
            422 => 'validation_failed',
            423 => 'resource_locked',
            429 => 'rate_limit_exceeded',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'internal_error' : 'request_failed',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonResponse(Response $original, array $payload): JsonResponse
    {
        $headers = $original->headers->all();
        unset($headers['content-length'], $headers['content-type']);

        $response = new JsonResponse($payload, $original->getStatusCode(), $headers);

        foreach ($original->headers->getCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
