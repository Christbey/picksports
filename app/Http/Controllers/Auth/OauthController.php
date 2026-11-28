<?php

namespace App\Http\Controllers\Auth;

use App\Models\OauthAccount;
use App\Models\User;
use App\Services\Auth\FoundingUserAccessService;
use App\Services\Billing\BillingRoleEntitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class OauthController
{
    public function redirect(string $provider): RedirectResponse
    {
        $this->assertProviderIsEnabled($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider, FoundingUserAccessService $foundingUserAccessService): RedirectResponse
    {
        $this->assertProviderIsEnabled($provider);

        try {
            /** @var SocialiteUser $socialiteUser */
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return redirect(route('login', absolute: false))->with('oauth_error', 'OAuth login failed. Please try again.');
        }

        if (! $this->hasVerifiedEmail($socialiteUser)) {
            return redirect(route('login', absolute: false))->with('oauth_error', 'This provider account must expose a verified email address.');
        }

        $providerSubject = $socialiteUser->getId();
        if ((! is_string($providerSubject) && ! is_int($providerSubject))
            || (string) $providerSubject === '' || strlen((string) $providerSubject) > 255
            || preg_match('/[\x00-\x20\x7f]/', (string) $providerSubject) === 1) {
            return redirect(route('login', absolute: false))->with('oauth_error', 'The provider did not supply a supported account identity.');
        }
        $providerSubject = (string) $providerSubject;

        try {
            $user = DB::transaction(function () use ($provider, $providerSubject, $socialiteUser, $foundingUserAccessService): User {
                $oauthAccount = OauthAccount::query()
                    ->with('user')
                    ->useWritePdo()
                    ->lockForUpdate()
                    ->where('provider', $provider)
                    ->where('provider_user_id', $providerSubject)
                    ->first();

                $user = $oauthAccount?->user;

                if (! $user) {
                    $user = User::query()
                        ->where('email', $socialiteUser->getEmail())
                        ->first();
                }

                if (! $user) {
                    $user = DB::transaction(function () use ($socialiteUser, $foundingUserAccessService): User {
                        $user = User::create([
                            'name' => $socialiteUser->getName() ?: $socialiteUser->getNickname() ?: Str::before((string) $socialiteUser->getEmail(), '@'),
                            'email' => (string) $socialiteUser->getEmail(),
                            'email_verified_at' => now(),
                            'age_verified_at' => null,
                            'password' => Str::password(32),
                        ]);

                        $grantedFoundingRole = $foundingUserAccessService->assignFoundingRoleIfEligible($user);

                        if (! $grantedFoundingRole) {
                            app(BillingRoleEntitlements::class)->grantNewAccountDefault($user, 'oauth');
                        }

                        return $user;
                    });
                }

                $oauthAccount ??= new OauthAccount([
                    'provider' => $provider,
                    'provider_user_id' => $providerSubject,
                ]);

                $oauthAccount->fill([
                    'email' => $socialiteUser->getEmail(),
                    'avatar' => $socialiteUser->getAvatar(),
                    'access_token' => $socialiteUser->token,
                    'refresh_token' => $socialiteUser->refreshToken,
                    'token_expires_at' => $socialiteUser->expiresIn ? now()->addSeconds((int) $socialiteUser->expiresIn) : null,
                    'provider_claims' => is_array($socialiteUser->getRaw()) ? $socialiteUser->getRaw() : [],
                    'last_used_at' => now(),
                ]);
                $oauthAccount->user()->associate($user);
                if ($oauthAccount->save() !== true || ! $oauthAccount->exists || $oauthAccount->getKey() === null) {
                    throw ValidationException::withMessages(['oauth' => 'OAuth identity persistence was cancelled.']);
                }
                $stored = $oauthAccount->newModelQuery()->useWritePdo()->whereKey($oauthAccount->getKey())
                    ->select(['id', 'user_id', 'provider', 'provider_user_id'])->lockForUpdate()->first();
                if ($stored === null || (string) $stored->user_id !== (string) $user->getKey()
                    || $stored->getRawOriginal('provider') !== $provider
                    || $stored->getRawOriginal('provider_user_id') !== $providerSubject) {
                    throw ValidationException::withMessages(['oauth' => 'OAuth identity owner changed before persistence completed.']);
                }

                return $user;
            });
        } catch (ValidationException) {
            return redirect(route('login', absolute: false))->with('oauth_error', 'OAuth login could not be completed. Please try again.');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        if (! $user->hasCompletedRequiredOnboarding()) {
            return redirect(route('oauth.onboarding.show', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function assertProviderIsEnabled(string $provider): void
    {
        if (! config("services.oauth.providers.{$provider}.enabled")) {
            throw new NotFoundHttpException;
        }
    }

    private function hasVerifiedEmail(SocialiteUser $socialiteUser): bool
    {
        $email = $socialiteUser->getEmail();
        $raw = $socialiteUser->getRaw();

        if (! is_string($email) || $email === '') {
            return false;
        }

        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? null;

        if ($verified !== null) {
            return filter_var($verified, FILTER_VALIDATE_BOOL);
        }

        return is_string($raw['email_verified_at'] ?? null) && $raw['email_verified_at'] !== '';
    }
}
