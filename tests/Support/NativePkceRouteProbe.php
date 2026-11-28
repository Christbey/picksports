<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Providers\NativeOAuthServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Laravel\Passport\PassportServiceProvider;
use League\OAuth2\Server\AuthorizationServer;

final class NativePkceObservedRoutes extends RouteCollection
{
    public array $additions = [];

    public function add(Route $route): Route
    {
        $this->additions[] = ['uri' => $route->uri(), 'methods' => $route->methods()];

        return parent::add($route);
    }
}

$root = $argv[1];
if (! is_dir($root) || ! is_file($root.'/.env.testing')) {
    throw new RuntimeException('Synthetic probe environment is required.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($root);
$app->loadEnvironmentFrom('.env.testing');
$app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
    if ($app->configurationIsCached() || is_file($app->getCachedRoutesPath())
        || $app['config']->get('database.default') !== 'sqlite'
        || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
        || $app['config']->get('database.connections.sqlite.url')) {
        throw new RuntimeException('Probe refuses cached or non-synthetic database configuration.');
    }
});
$observed = new NativePkceObservedRoutes;
$app->beforeBootstrapping(BootProviders::class, fn ($app) => $app->make('router')->setRoutes($observed));
$app->make(Kernel::class)->bootstrap();
$counts = [];
foreach (['GET oauth/authorize', 'POST oauth/authorize', 'DELETE oauth/authorize', 'POST oauth/token'] as $entry) {
    [$method, $uri] = explode(' ', $entry, 2);
    $counts[$entry] = count(array_filter($observed->additions, fn (array $route): bool => $route['uri'] === $uri && in_array($method, $route['methods'], true)));
}
$loaded = $app->getLoadedProviders();
$binding = $app->getBindings()[AuthorizationServer::class]['concrete'];
echo json_encode(['route_add_counts' => $counts, 'vendor_provider_loaded' => isset($loaded[PassportServiceProvider::class]), 'native_provider_loaded' => isset($loaded[NativeOAuthServiceProvider::class]), 'server_factory_owner' => (new ReflectionFunction($binding))->getClosureThis()::class, 'synthetic_environment_path' => $app->environmentPath()], JSON_THROW_ON_ERROR);
