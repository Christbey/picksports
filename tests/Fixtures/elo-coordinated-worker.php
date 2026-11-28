<?php

use App\Actions\NFL\CalculateElo;
use App\Models\NFL\Game;
use App\Services\Sports\Elo\EloSeasonRollover;
use App\Services\Sports\Elo\EloWriterCapabilities;
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
        throw new RuntimeException('Elo worker refuses cached configuration.');
    }
});
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    TestDatabaseSafety::assertSafe($app['config']->all(), (bool) $app['config_loaded_from_cache'], Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if ($app['config']->get('database.default') !== 'mysql') {
        throw new RuntimeException('Elo worker requires disposable MySQL.');
    }
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Queue::fake();
app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
{
    public function supports(string $sport, int $protocol): bool
    {
        return $sport === 'nfl' && $protocol === 1;
    }
});
config(['elo_integrity.enabled' => true,
    'elo_integrity.write_target_fingerprints.nfl' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
[$mode, $id, $directory, $label] = array_slice($argv, 1);
if (! in_array($mode, ['apply', 'rollover'], true) || ! in_array($label, ['one', 'two', 'rollover', 'game'], true)
    || ! is_dir($directory) || is_link($directory) || ! str_starts_with($directory, sys_get_temp_dir().'/picksports-elo-race-')) {
    throw new RuntimeException('Invalid isolated Elo worker arguments.');
}
touch($directory.'/ready-'.$label);
$deadline = microtime(true) + 15;
while (! is_file($directory.'/go')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Elo worker barrier deadline exceeded.');
    }
    usleep(10000);
}
try {
    $result = $mode === 'apply' ? (new CalculateElo)->execute(Game::findOrFail((int) $id))
        : app(EloSeasonRollover::class)->execute('nfl', (int) $id, ['team' => '0.3']);
    file_put_contents($directory.'/result-'.$label, json_encode(['status' => 'succeeded', 'skipped' => $result['skipped'] ?? false], JSON_THROW_ON_ERROR));
} catch (RuntimeException) {
    file_put_contents($directory.'/result-'.$label, json_encode(['status' => 'refused'], JSON_THROW_ON_ERROR));
}
