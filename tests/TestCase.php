<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        // Reject cache files before evaluating their contents; check the resolved
        // environment/URL after config loads, before providers or database traits.
        $app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
            if ($app->configurationIsCached()) {
                throw new RuntimeException('Test database safety refuses cached application configuration.');
            }
        });
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            TestDatabaseSafety::assertSafe(
                $app['config']->all(),
                (bool) $app['config_loaded_from_cache'],
                Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'),
                Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'),
            );
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
