<?php

use App\Actions\ESPN\NFL\SyncPlayerInjuries;
use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Models\NFL\Team;
use App\Models\ProviderRefreshAttempt;
use App\Models\ProviderRefreshHead;
use App\Services\ESPN\BaseEspnService;
use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Refresh worker refuses cached configuration.');
    }
});
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    TestDatabaseSafety::assertSafe($app['config']->all(), (bool) $app['config_loaded_from_cache'], Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if ($app['config']->get('database.default') !== 'mysql') {
        throw new RuntimeException('Refresh worker requires disposable MySQL.');
    }
});
$app->make(Kernel::class)->bootstrap();
$privateRoot = dirname(__DIR__, 2);
$privatePaths = [];
foreach ([ProviderRefreshCoordinator::class, ProviderRefreshAttempt::class, ProviderRefreshHead::class,
    Team::class, BaseEspnService::class, Application::class] as $class) {
    $privatePaths[$class] = (new ReflectionClass($class))->getFileName();
    if (! str_starts_with($privatePaths[$class], $privateRoot.'/')) {
        throw new RuntimeException('Refresh worker escaped private source.');
    }
}
if ($app->basePath() !== $privateRoot || $app->storagePath() !== $privateRoot.'/storage'
    || $app->configurationIsCached() || is_file($privateRoot.'/.env')) {
    throw new RuntimeException('Refresh worker environment isolation failed.');
}
Http::preventStrayRequests();
Queue::fake();
[$component, $role, $id, $directory, $newerFails] = array_slice($argv, 1);
if (! in_array($component, ['injuries', 'depth_chart', 'reserve'], true) || ! in_array($role, ['older', 'newer', 'one', 'two'], true)
    || ! ctype_digit($id) || ! is_dir($directory) || is_link($directory)
    || ! str_starts_with($directory, sys_get_temp_dir().'/picksports-refresh-race-')) {
    throw new RuntimeException('Invalid isolated refresh worker arguments.');
}
config(['provider_refresh.enabled' => true, 'provider_refresh.write_target_fingerprint' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
$barrier = static function (string $path): void {
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Refresh worker barrier exceeded its deadline.');
        }
        usleep(10000);
    }
};
try {
    if ($component === 'reserve') {
        touch($directory.'/ready-'.$role);
        $barrier($directory.'/go');
        $context = app(ProviderRefreshCoordinator::class)->begin(Team::findOrFail((int) $id), 'injuries', 0);
        $sequence = $context->attempt->sequence;
        $context->finish();
        $result = ['status' => 'completed', 'sequence' => $sequence];
    } else {
        $service = new class($role, $directory, $newerFails === '1', $barrier) extends BaseEspnService
        {
            protected const SPORT_KEY = 'nfl';

            public function __construct(private string $role, private string $directory, private bool $newerFails, private Closure $barrier) {}

            private function acquired(): bool
            {
                if ($this->role === 'older') {
                    touch($this->directory.'/acquired-older');
                    ($this->barrier)($this->directory.'/release-older');
                }

                return $this->role === 'newer' && $this->newerFails;
            }

            public function getTeamInjuries(string $teamId): ?array
            {
                if ($this->acquired()) {
                    return null;
                }

                return ['items' => [['id' => 'known', 'athlete' => ['id' => '123'], 'status' => $this->role === 'older' ? 'Questionable' : 'Doubtful']]];
            }

            public function getRoster(string $teamId): ?array
            {
                return null;
            }

            public function getTeamDepthCharts(string $teamId, int $season): ?array
            {
                if ($this->acquired()) {
                    return null;
                }

                return ['items' => [['positions' => ['qb' => ['athletes' => [['rank' => $this->role === 'older' ? 2 : 3, 'athlete' => ['id' => '123']]]]]]]];
            }
        };
        $count = $component === 'injuries' ? (new SyncPlayerInjuries($service))->execute('22') : (new SyncTeamDepthCharts($service))->execute('22', 2026);
        $result = ['status' => 'completed', 'count' => $count];
    }
} catch (RuntimeException) {
    $result = ['status' => 'refused'];
}
file_put_contents($directory.'/result-'.$role, json_encode([...$result, 'private_paths_verified' => true,
    'class_paths' => $privatePaths, 'base_path' => $app->basePath(), 'storage_path' => $app->storagePath()], JSON_THROW_ON_ERROR));
