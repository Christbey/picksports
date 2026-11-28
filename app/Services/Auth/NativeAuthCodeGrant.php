<?php

namespace App\Services\Auth;

use DateInterval;
use InvalidArgumentException;
use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

final class NativeAuthCodeGrant extends AuthCodeGrant
{
    public function __construct(
        AuthCodeRepositoryInterface $authCodes,
        RefreshTokenRepositoryInterface $refreshTokens,
        DateInterval $authCodeTTL,
        private readonly NativeOAuthPkcePolicy $policy,
    ) {
        parent::__construct($authCodes, $refreshTokens, $authCodeTTL);
    }

    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        $authorization = parent::validateAuthorizationRequest($request);
        $this->assertNativeAuthorization($authorization);

        return $authorization;
    }

    public function completeAuthorizationRequest(AuthorizationRequestInterface $authorizationRequest): ResponseTypeInterface
    {
        // A pre-deployment consent session must not issue a new plain native code.
        if ($authorizationRequest->isAuthorizationApproved()) {
            $this->assertNativeAuthorization($authorizationRequest);
        }

        return parent::completeAuthorizationRequest($authorizationRequest);
    }

    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseTypeInterface $responseType, DateInterval $accessTokenTTL): ResponseTypeInterface
    {
        $client = $this->validateClient($request);
        if ($this->policy->requiresS256($client->getIdentifier())) {
            $code = $this->getRequestParameter('code', $request);
            if ($code === null) {
                throw OAuthServerException::invalidRequest('code');
            }
            try {
                // Standard League authenticated decryption; a request-side method cannot upgrade the signed code.
                $payload = json_decode($this->decrypt($code), false, 64, JSON_THROW_ON_ERROR);
            } catch (InvalidArgumentException|LogicException|JsonException) {
                throw OAuthServerException::invalidGrant('The authorization code is invalid.');
            }
            if (! $payload instanceof \stdClass || ($payload->code_challenge_method ?? null) !== 'S256'
                || ! is_string($payload->code_challenge ?? null) || $payload->code_challenge === '') {
                throw OAuthServerException::invalidGrant('Native authorization codes require S256 PKCE.');
            }
        }

        // Keep normal code/client/redirect/expiry/reuse/verifier/scopes/refresh validation and persistence.
        return parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
    }

    private function assertNativeAuthorization(AuthorizationRequestInterface $request): void
    {
        if ($this->policy->requiresS256($request->getClient()->getIdentifier())
            && ($request->getCodeChallengeMethod() !== 'S256' || $request->getCodeChallenge() === null)) {
            throw OAuthServerException::invalidRequest('code_challenge_method', 'Native authorization requires S256 PKCE.');
        }
    }
}
