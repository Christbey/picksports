<?php

namespace App\Providers;

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use App\Actions\ESPN\NBA\SyncPlayerInjuries;
use App\Events\GameFinalized;
use App\Listeners\TriggerGameFinalizationGrading;
use App\Models\DeveloperApiCredential;
use App\Services\CommandHeartbeatService;
use App\Services\DeveloperPlatform\DeveloperApiCredentialAuthenticator;
use App\Services\ESPN\NBA\EspnService;
use App\Services\Predictions\ModelRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use Symfony\Component\Console\Input\InputInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModelRunRecorder::class);
        $this->registerEspnScoreboardSyncActions();
        $this->registerEspnPlayerInjurySyncActions();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDeveloperApiGuard();
        $this->configurePassport();
        $this->configureApiRateLimiters();
        $this->configureFactories();
        $this->registerEventListeners();
    }

    protected function configurePassport(): void
    {
        Passport::authorizationView('auth.oauth.authorize');
        Passport::tokensCan([
            'mobile:read' => 'Read PickSports data available to your account.',
            'mobile:write' => 'Manage your bets, groups, brackets, alerts, and device settings.',
        ]);
        Passport::setDefaultScope(['mobile:read']);
        Passport::tokensExpireIn(now()->addMinutes(max(5, (int) config('native_auth.access_token_ttl_minutes', 15))));
        Passport::refreshTokensExpireIn(now()->addDays(max(1, (int) config('native_auth.refresh_token_ttl_days', 30))));
    }

    protected function configureDeveloperApiGuard(): void
    {
        Auth::viaRequest('developer-api-token', function (Request $request): ?DeveloperApiCredential {
            $token = $request->bearerToken();

            return is_string($token) && $token !== ''
                ? app(DeveloperApiCredentialAuthenticator::class)->authenticate($token)
                : null;
        });
    }

    protected function configureApiRateLimiters(): void
    {
        RateLimiter::for('api-v2-auth-login', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('api.v2.rate_limits.auth_login_per_minute', 10)),
        )->by('api-v2-auth-login:'.$request->ip()));

        RateLimiter::for('api-v2-auth-passkey-options', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('api.v2.rate_limits.auth_passkey_options_per_minute', 20)),
        )->by('api-v2-auth-passkey-options:'.$request->ip()));

        RateLimiter::for('api-v2-auth-passkey-verify', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('api.v2.rate_limits.auth_passkey_verify_per_minute', 10)),
        )->by('api-v2-auth-passkey-verify:'.$request->ip()));

        RateLimiter::for('api-v2-writes', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('api.v2.rate_limits.writes_per_minute', 60)),
        )->by('api-v2-writes:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    protected function registerEspnScoreboardSyncActions(): void
    {
        $bindings = [
            SyncGamesFromScoreboard::class => EspnService::class,
            \App\Actions\ESPN\NFL\SyncGamesFromScoreboard::class => \App\Services\ESPN\NFL\EspnService::class,
            \App\Actions\ESPN\MLB\SyncGamesFromScoreboard::class => \App\Services\ESPN\MLB\EspnService::class,
            \App\Actions\ESPN\CBB\SyncGamesFromScoreboard::class => \App\Services\ESPN\CBB\EspnService::class,
            \App\Actions\ESPN\CFB\SyncGamesFromScoreboard::class => \App\Services\ESPN\CFB\EspnService::class,
            \App\Actions\ESPN\WCBB\SyncGamesFromScoreboard::class => \App\Services\ESPN\WCBB\EspnService::class,
            \App\Actions\ESPN\WNBA\SyncGamesFromScoreboard::class => \App\Services\ESPN\WNBA\EspnService::class,
        ];

        foreach ($bindings as $actionClass => $serviceClass) {
            $this->app->bind(
                $actionClass,
                fn () => new $actionClass(new $serviceClass),
            );
        }
    }

    protected function registerEspnPlayerInjurySyncActions(): void
    {
        $bindings = [
            SyncPlayerInjuries::class => EspnService::class,
            \App\Actions\ESPN\NFL\SyncPlayerInjuries::class => \App\Services\ESPN\NFL\EspnService::class,
            \App\Actions\ESPN\MLB\SyncPlayerInjuries::class => \App\Services\ESPN\MLB\EspnService::class,
            \App\Actions\ESPN\CBB\SyncPlayerInjuries::class => \App\Services\ESPN\CBB\EspnService::class,
            \App\Actions\ESPN\CFB\SyncPlayerInjuries::class => \App\Services\ESPN\CFB\EspnService::class,
            \App\Actions\ESPN\WCBB\SyncPlayerInjuries::class => \App\Services\ESPN\WCBB\EspnService::class,
            \App\Actions\ESPN\WNBA\SyncPlayerInjuries::class => \App\Services\ESPN\WNBA\EspnService::class,
        ];

        foreach ($bindings as $actionClass => $serviceClass) {
            $this->app->bind(
                $actionClass,
                fn () => new $actionClass(new $serviceClass),
            );
        }
    }

    protected function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $factoryBaseName = collect(explode('\\', Str::after($modelName, 'App\\Models\\')))
                ->filter()
                ->map(fn (string $segment): string => Str::studly(Str::lower($segment)))
                ->implode('');

            return 'Database\\Factories\\'.$factoryBaseName.'Factory';
        });

        Factory::guessModelNamesUsing(function (Factory $factory): string {
            $factoryBaseName = Str::replaceLast('Factory', '', class_basename($factory));

            foreach (['Wcbb', 'Wnba', 'Cbb', 'Cfb', 'Mlb', 'Nba', 'Nfl'] as $prefix) {
                if (! Str::startsWith($factoryBaseName, $prefix)) {
                    continue;
                }

                return 'App\\Models\\'.strtoupper($prefix).'\\'.Str::after($factoryBaseName, $prefix);
            }

            return 'App\\Models\\'.$factoryBaseName;
        });
    }

    protected function registerEventListeners(): void
    {
        Event::listen(GameFinalized::class, TriggerGameFinalizationGrading::class);
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            $this->recordPipelineCommandHeartbeat($event);
        });
    }

    protected function recordPipelineCommandHeartbeat(CommandFinished $event): void
    {
        if ($event->exitCode !== 0) {
            return;
        }

        $command = $this->renderConsoleCommand($event->command ?? '', $event->input);
        if ($command === '' || ! $this->isPipelineHeartbeatCommand($command)) {
            return;
        }

        try {
            if (! Schema::hasTable('command_heartbeats')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        app(CommandHeartbeatService::class)->recordSuccess($command, null, 'manual', [
            'recorded_by' => 'command_finished_listener',
        ]);
    }

    protected function isPipelineHeartbeatCommand(string $command): bool
    {
        foreach ((array) config('validation.sports', []) as $profile) {
            foreach ((array) data_get($profile, 'pipeline_order', []) as $rule) {
                foreach (array_merge((array) ($rule['upstream'] ?? []), (array) ($rule['downstream'] ?? [])) as $pattern) {
                    $glob = str_replace('%', '*', (string) $pattern);
                    if (Str::is($glob, $command)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function renderConsoleCommand(string $command, InputInterface $input): string
    {
        if ($command === '') {
            return '';
        }

        $tokens = [$command];

        foreach ($input->getOptions() as $name => $value) {
            if ($value === null || $value === false || $value === '' || $name === 'help' || $name === 'quiet' || $name === 'verbose' || $name === 'version' || $name === 'ansi' || $name === 'no-interaction' || $name === 'env') {
                continue;
            }

            if ($value === true) {
                $tokens[] = "--{$name}";

                continue;
            }

            foreach ((array) $value as $item) {
                if ($item === null || $item === '') {
                    continue;
                }

                $tokens[] = "--{$name}={$item}";
            }
        }

        return implode(' ', $tokens);
    }
}
