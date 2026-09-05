<?php

use App\Support\Api\ApiV2ErrorResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('echoes a safe client request id on successful v2 responses', function () {
    $requestId = 'mobile:01HZX9Y7M9.client-request_42';

    $this->withHeader(ApiV2ErrorResponse::REQUEST_ID_HEADER, $requestId)
        ->getJson('/api/v2/sports')
        ->assertOk()
        ->assertHeader(ApiV2ErrorResponse::REQUEST_ID_HEADER, $requestId)
        ->assertJsonStructure(['data', 'meta']);
});

it('replaces unsafe client request ids with a generated ulid', function () {
    $response = $this->withHeader(ApiV2ErrorResponse::REQUEST_ID_HEADER, '../../unsafe request id')
        ->getJson('/api/v2/sports')
        ->assertOk();

    $requestId = $response->headers->get(ApiV2ErrorResponse::REQUEST_ID_HEADER);

    expect($requestId)
        ->not->toBe('../../unsafe request id')
        ->and(Str::isUlid((string) $requestId))->toBeTrue();
});

it('uses the standard envelope for v2 http exceptions', function () {
    $requestId = 'web.not-found-42';

    $this->withHeader(ApiV2ErrorResponse::REQUEST_ID_HEADER, $requestId)
        ->get('/api/v2/sports/nhl', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertHeader(ApiV2ErrorResponse::REQUEST_ID_HEADER, $requestId)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'Unsupported sport: nhl')
        ->assertJsonPath('error.request_id', $requestId)
        ->assertJsonPath('request_id', $requestId);
});

it('preserves validation status and field errors in the standard envelope', function () {
    $response = $this->postJson('/api/v2/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure([
            'error' => [
                'code',
                'message',
                'request_id',
                'fields' => ['email', 'password'],
            ],
            'request_id',
        ]);

    expect($response->json('request_id'))
        ->toBe($response->headers->get(ApiV2ErrorResponse::REQUEST_ID_HEADER));
});

it('preserves authentication status in the standard envelope', function () {
    $response = $this->getJson('/api/v2/live-scoreboard')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('error.message', 'Unauthenticated.');

    expect($response->json('request_id'))
        ->toBe($response->headers->get(ApiV2ErrorResponse::REQUEST_ID_HEADER));
});

it('does not expose exception details from v2 server errors', function () {
    Route::get('/api/v2/__transport-test/server-error', function (): void {
        throw new RuntimeException('sensitive provider credential');
    });

    $response = $this->getJson('/api/v2/__transport-test/server-error')
        ->assertInternalServerError()
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.message', 'An unexpected error occurred.')
        ->assertJsonMissing(['exception'])
        ->assertJsonMissing(['trace']);

    expect($response->getContent())->not->toContain('sensitive provider credential');
});

it('redacts server errors that were already returned in an error envelope', function () {
    Route::get('/api/v2/__transport-test/enveloped-server-error', fn () => response()->json([
        'error' => [
            'code' => 'provider_failure',
            'message' => 'sensitive upstream response',
            'fields' => ['provider' => ['secret detail']],
        ],
        'message' => 'sensitive upstream response',
        'exception' => 'SensitiveException',
    ], 500));

    $response = $this->getJson('/api/v2/__transport-test/enveloped-server-error')
        ->assertInternalServerError()
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.message', 'An unexpected error occurred.')
        ->assertJsonMissingPath('error.fields')
        ->assertJsonMissingPath('exception');

    expect($response->getContent())->not->toContain('sensitive');
});
