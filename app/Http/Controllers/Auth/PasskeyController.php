<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PasskeyController extends Controller
{
    public function __construct(private readonly PasskeyService $passkeyService) {}

    public function index(Request $request): JsonResponse
    {
        $passkeys = $request->user()
            ->passkeys()
            ->latest()
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        return response()->json([
            'passkeys' => $passkeys,
        ]);
    }

    public function registrationOptions(Request $request): JsonResponse
    {
        $this->ensurePasskeysEnabled();

        $user = $request->user();
        $challenge = $this->passkeyService->generateChallenge();
        $rpId = $this->resolveRpId($request);
        $origin = $this->resolveOrigin($request);

        $request->session()->put('passkeys.register', [
            'challenge' => $challenge,
            'user_id' => $user->id,
            'rp_id' => $rpId,
            'origin' => $origin,
            'expires_at' => now()->addSeconds((int) config('passkeys.challenge_timeout_seconds', 300))->timestamp,
        ]);

        $excludeCredentials = $user->passkeys
            ->map(fn (Passkey $passkey): array => array_filter([
                'id' => $passkey->credential_id,
                'type' => 'public-key',
                'transports' => $passkey->transports,
            ]))
            ->values()
            ->all();

        return response()->json([
            'publicKey' => [
                'challenge' => $challenge,
                'rp' => [
                    'name' => (string) config('app.name'),
                    'id' => $rpId,
                ],
                'user' => [
                    'id' => $this->passkeyService->base64UrlEncode((string) $user->id),
                    'name' => (string) $user->email,
                    'displayName' => (string) $user->name,
                ],
                'pubKeyCredParams' => collect(config('passkeys.algorithms', [-7]))
                    ->map(fn (int $alg): array => ['type' => 'public-key', 'alg' => $alg])
                    ->values()
                    ->all(),
                'timeout' => 60000,
                'attestation' => 'none',
                'authenticatorSelection' => [
                    'residentKey' => 'required',
                    'requireResidentKey' => true,
                    'userVerification' => (string) config('passkeys.user_verification', 'required'),
                ],
                'excludeCredentials' => $excludeCredentials,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $this->ensurePasskeysEnabled();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'credential_id' => ['required', 'string', 'max:512'],
            'public_key' => ['nullable', 'string', 'max:4096'],
            'attestation_object' => ['nullable', 'string', 'max:12000'],
            'algorithm' => ['nullable', 'integer', Rule::in(config('passkeys.algorithms', [-7]))],
            'client_data_json' => ['required', 'string', 'max:4096'],
            'authenticator_data' => ['nullable', 'string', 'max:4096'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string', 'in:usb,nfc,ble,internal,hybrid'],
        ]);

        if (empty($validated['public_key']) && empty($validated['attestation_object'])) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey enrollment payload is incomplete.',
            ]);
        }

        $sessionPayload = $request->session()->pull('passkeys.register');

        if (! is_array($sessionPayload) || ($sessionPayload['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey registration challenge expired. Please try again.',
            ]);
        }

        if (($sessionPayload['user_id'] ?? null) !== $request->user()->id) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey registration user mismatch.',
            ]);
        }

        $this->passkeyService->validateClientData(
            $validated['client_data_json'],
            (string) $sessionPayload['challenge'],
            'webauthn.create',
            (string) ($sessionPayload['origin'] ?? ''),
        );

        $authenticatorDataInput = $validated['authenticator_data'] ?? null;
        $publicKeyPem = null;
        $algorithm = $validated['algorithm'] ?? null;

        if (! empty($validated['public_key'])) {
            $publicKeyPem = $this->passkeyService->publicKeyPemFromSpki($validated['public_key']);
        }

        if (! $publicKeyPem) {
            $parsed = $this->passkeyService->extractCredentialFromAttestationObject((string) $validated['attestation_object']);

            if ($parsed['credentialId'] !== $validated['credential_id']) {
                throw ValidationException::withMessages([
                    'credential' => 'Passkey credential ID mismatch.',
                ]);
            }

            $publicKeyPem = $parsed['publicKeyPem'];
            $algorithm ??= $parsed['algorithm'];
            $authenticatorDataInput ??= $parsed['authenticatorData'];
        }

        if (! $authenticatorDataInput) {
            throw ValidationException::withMessages([
                'credential' => 'Authenticator data is required to complete passkey enrollment.',
            ]);
        }

        $authenticatorData = $this->passkeyService->validateAuthenticatorData(
            $authenticatorDataInput,
            (string) ($sessionPayload['rp_id'] ?? ''),
            requireUserVerification: true,
        );

        $existing = Passkey::query()->where('credential_id', $validated['credential_id'])->first();

        if ($existing && $existing->user_id !== $request->user()->id) {
            throw ValidationException::withMessages([
                'credential' => 'This passkey is already registered to another account.',
            ]);
        }

        $passkey = Passkey::query()->updateOrCreate(
            ['credential_id' => $validated['credential_id']],
            [
                'user_id' => $request->user()->id,
                'name' => $validated['name'] ?? null,
                'public_key' => $publicKeyPem,
                'algorithm' => (int) ($algorithm ?? -7),
                'sign_count' => $authenticatorData['signCount'],
                'transports' => $validated['transports'] ?? null,
            ],
        );

        return response()->json([
            'message' => 'Passkey saved.',
            'passkey' => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'last_used_at' => $passkey->last_used_at,
                'created_at' => $passkey->created_at,
            ],
        ]);
    }

    public function destroy(Request $request, Passkey $passkey): JsonResponse
    {
        if ($passkey->user_id !== $request->user()->id) {
            abort(404);
        }

        $passkey->delete();

        return response()->json([
            'message' => 'Passkey deleted.',
        ]);
    }

    public function authenticationOptions(Request $request): JsonResponse
    {
        $this->ensurePasskeysEnabled();

        $validated = $request->validate([
            'email' => ['nullable', 'email:rfc'],
        ]);

        $user = null;

        if (! empty($validated['email'])) {
            $user = User::query()->where('email', $validated['email'])->first();
        }

        $challenge = $this->passkeyService->generateChallenge();
        $rpId = $this->resolveRpId($request);
        $origin = $this->resolveOrigin($request);

        $request->session()->put('passkeys.authenticate', [
            'challenge' => $challenge,
            'user_id' => $user?->id,
            'rp_id' => $rpId,
            'origin' => $origin,
            'expires_at' => now()->addSeconds((int) config('passkeys.challenge_timeout_seconds', 300))->timestamp,
        ]);

        $allowCredentials = $user
            ? $user->passkeys->map(fn (Passkey $passkey): array => array_filter([
                'type' => 'public-key',
                'id' => $passkey->credential_id,
                'transports' => $passkey->transports,
            ]))->values()->all()
            : [];

        return response()->json([
            'publicKey' => [
                'challenge' => $challenge,
                'rpId' => $rpId,
                'timeout' => 60000,
                'userVerification' => (string) config('passkeys.user_verification', 'required'),
                'allowCredentials' => $allowCredentials,
            ],
        ]);
    }

    public function authenticate(Request $request): JsonResponse
    {
        $this->ensurePasskeysEnabled();

        $validated = $request->validate([
            'credential_id' => ['required', 'string', 'max:512'],
            'client_data_json' => ['required', 'string', 'max:4096'],
            'authenticator_data' => ['required', 'string', 'max:4096'],
            'signature' => ['required', 'string', 'max:4096'],
        ]);

        $sessionPayload = $request->session()->pull('passkeys.authenticate');

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

        Auth::login($passkey->user, remember: true);
        $request->session()->regenerate();

        return response()->json([
            'redirect' => route('dashboard'),
        ]);
    }

    private function ensurePasskeysEnabled(): void
    {
        abort_unless((bool) config('passkeys.enabled', true), 404);
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
