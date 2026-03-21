<?php

namespace App\Services\Auth;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PasskeyAuthenticationService
{
    private const CACHE_PREFIX = 'passkeys:authenticate:';

    public function __construct(private readonly PasskeyService $passkeyService) {}

    /**
     * @return array{publicKey:array<string,mixed>}
     */
    public function buildAuthenticationOptions(
        Request $request,
        ?string $email = null,
        string $stateKey = 'passkeys.authenticate',
        bool $useSession = true,
    ): array {
        $user = null;

        if ($email !== null && trim($email) !== '') {
            $user = User::query()->where('email', strtolower(trim($email)))->first();
        }

        $challenge = $this->passkeyService->generateChallenge();
        $rpId = $this->resolveRpId($request);
        $origin = $this->resolveOrigin($request);

        $state = [
            'challenge' => $challenge,
            'user_id' => $user?->id,
            'rp_id' => $rpId,
            'origin' => $origin,
            'expires_at' => now()->addSeconds((int) config('passkeys.challenge_timeout_seconds', 300))->timestamp,
        ];

        $challengeId = null;
        if ($useSession) {
            $request->session()->put($stateKey, $state);
        } else {
            $challengeId = $this->passkeyService->base64UrlEncode(random_bytes(24));
            Cache::put(
                self::CACHE_PREFIX.$challengeId,
                $state,
                now()->addSeconds((int) config('passkeys.challenge_timeout_seconds', 300))
            );
        }

        $allowCredentials = $user
            ? $user->passkeys->map(fn (Passkey $passkey): array => array_filter([
                'type' => 'public-key',
                'id' => $passkey->credential_id,
                'transports' => $passkey->transports,
            ]))->values()->all()
            : [];

        $response = [
            'publicKey' => [
                'challenge' => $challenge,
                'rpId' => $rpId,
                'timeout' => max(5000, (int) config('passkeys.authentication_timeout_ms', 20000)),
                'userVerification' => (string) config('passkeys.user_verification', 'required'),
                'allowCredentials' => $allowCredentials,
            ],
        ];

        if (! $useSession && $challengeId !== null) {
            $response['challenge_id'] = $challengeId;
        }

        return $response;
    }

    /**
     * @param  array{credential_id:string,client_data_json:string,authenticator_data:string,signature:string,challenge_id?:string}  $validated
     */
    public function verifyAuthentication(
        Request $request,
        array $validated,
        string $stateKey = 'passkeys.authenticate',
        bool $useSession = true,
    ): User {
        $sessionPayload = $this->pullStatePayload($request, $validated, $stateKey, $useSession);

        if (! is_array($sessionPayload) || ($sessionPayload['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey login challenge expired. Please try again.',
            ]);
        }

        $passkey = Passkey::query()->with('user')->where('credential_id', $validated['credential_id'])->first();

        if (! $passkey) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey not recognized.',
            ]);
        }

        if (($sessionPayload['user_id'] ?? null) && $passkey->user_id !== $sessionPayload['user_id']) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey does not match the requested account.',
            ]);
        }

        $this->passkeyService->validateClientData(
            $validated['client_data_json'],
            (string) $sessionPayload['challenge'],
            'webauthn.get',
            (string) ($sessionPayload['origin'] ?? ''),
        );

        $authenticatorData = $this->passkeyService->validateAuthenticatorData(
            $validated['authenticator_data'],
            (string) ($sessionPayload['rp_id'] ?? ''),
            requireUserVerification: true,
        );

        if (! $this->passkeyService->verifyAssertionSignature(
            $passkey->public_key,
            $validated['authenticator_data'],
            $validated['client_data_json'],
            $validated['signature'],
        )) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey signature verification failed.',
            ]);
        }

        $nextSignCount = $authenticatorData['signCount'];

        if ($nextSignCount > 0 && $passkey->sign_count > 0 && $nextSignCount < $passkey->sign_count) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey counter check failed. Please use another sign-in method.',
            ]);
        }

        $passkey->forceFill([
            'sign_count' => max($passkey->sign_count, $nextSignCount),
            'last_used_at' => now(),
        ])->save();

        return $passkey->user;
    }

    /**
     * @param  array{challenge_id?:string}  $validated
     * @return array{challenge:string,user_id:int|null,rp_id:string,origin:string,expires_at:int}
     */
    private function pullStatePayload(Request $request, array $validated, string $stateKey, bool $useSession): array
    {
        if ($useSession) {
            $payload = $request->session()->pull($stateKey);

            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'credential' => 'Passkey login challenge expired. Please try again.',
                ]);
            }

            return $payload;
        }

        $challengeId = trim((string) ($validated['challenge_id'] ?? ''));

        if ($challengeId === '') {
            throw ValidationException::withMessages([
                'challenge_id' => 'Passkey challenge identifier is required.',
            ]);
        }

        $cacheKey = self::CACHE_PREFIX.$challengeId;
        $payload = Cache::get($cacheKey);
        Cache::forget($cacheKey);

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey login challenge expired. Please try again.',
            ]);
        }

        return $payload;
    }

    private function resolveRpId(Request $request): string
    {
        $configured = trim((string) config('passkeys.rp_id', ''));

        if ($configured !== '') {
            return $configured;
        }

        return $request->getHost();
    }

    private function resolveOrigin(Request $request): string
    {
        $configured = trim((string) config('passkeys.origin', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }
}
