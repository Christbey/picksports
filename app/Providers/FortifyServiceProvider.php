<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RegisterResponse::class, \App\Http\Responses\BracketInviteRegisterResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'oauthError' => $request->session()->get('oauth_error'),
            'oauthProviders' => $this->oauthProviders(),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'oauthError' => $request->session()->get('oauth_error'),
            'oauthProviders' => $this->oauthProviders(),
            'access' => $this->registerAccess($request),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    private function registerAccess(Request $request): ?array
    {
        $inviteToken = (string) $request->query('invite', $request->session()->get('group_invitation.token', ''));
        if ($inviteToken !== '') {
            $invitation = GroupInvitation::query()
                ->with('group')
                ->where('token', $inviteToken)
                ->first();

            if ($invitation && $invitation->isPending()) {
                return [
                    'token' => $invitation->token,
                    'token_field' => 'invite_token',
                    'email' => $invitation->email,
                    'group_name' => $invitation->group?->name,
                    'mode' => 'invite',
                ];
            }
        }

        $joinToken = (string) $request->query('join', $request->session()->get('group_join_link.token', ''));
        if ($joinToken === '') {
            return null;
        }

        $joinLink = GroupJoinLink::query()
            ->with('group')
            ->where('token', $joinToken)
            ->first();

        if (! $joinLink || ! $joinLink->isActive()) {
            return null;
        }

        return [
            'token' => $joinLink->token,
            'token_field' => 'join_token',
            'email' => null,
            'group_name' => $joinLink->group?->name,
            'mode' => 'join_link',
        ];
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }

    /**
     * @return list<array{key: string, label: string, href: string}>
     */
    private function oauthProviders(): array
    {
        $providers = (array) config('services.oauth.providers', []);

        return collect($providers)
            ->filter(fn (array $provider) => ($provider['enabled'] ?? false) === true)
            ->map(fn (array $provider, string $key) => [
                'key' => $key,
                'label' => (string) ($provider['label'] ?? Str::headline($key)),
                'href' => route('oauth.redirect', ['provider' => $key]),
            ])
            ->values()
            ->all();
    }
}
