<?php

namespace App\Providers;

use App\Actions\ESPN\NBA\SyncGamesFromScoreboard;
use App\Actions\ESPN\NBA\SyncPlayerInjuries;
use App\Events\GameFinalized;
use App\Listeners\TriggerGameFinalizationGrading;
use App\Services\CommandHeartbeatService;
use App\Services\ESPN\NBA\EspnService;
use App\Services\Predictions\ModelRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
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
