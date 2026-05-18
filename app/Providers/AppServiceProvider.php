<?php

namespace App\Providers;

use App\Events\GameFinalized;
use App\Listeners\TriggerGameFinalizationGrading;
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
        // Wire MLB scoreboard sync with the MLB-specific EspnService so the container
        // does not autowire the abstract BaseEspnService (which lacks MLB endpoints).
        $this->app->bind(
            \App\Actions\ESPN\MLB\SyncGamesFromScoreboard::class,
            fn ($app) => new \App\Actions\ESPN\MLB\SyncGamesFromScoreboard(
                new \App\Services\ESPN\MLB\EspnService
            ),
        );
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
