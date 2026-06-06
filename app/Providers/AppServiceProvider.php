<?php

namespace App\Providers;

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use App\Events\GameFinalized;
use App\Listeners\TriggerGameFinalizationGrading;
use App\Services\ESPN\NBA\EspnService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerEspnScoreboardSyncActions();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureFactories();
        $this->registerEventListeners();
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
    }
}
