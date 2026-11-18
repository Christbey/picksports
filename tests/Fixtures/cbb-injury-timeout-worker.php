<?php

use App\Actions\ESPN\CBB\SyncPlayerInjuries;
use App\Jobs\ESPN\CBB\FetchPlayerInjuries;
use App\Services\ESPN\CBB\EspnService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = $argv[1];
config()->set([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $directory.'/queue.sqlite',
    'queue.default' => 'database',
    'queue.connections.database.connection' => 'sqlite',
    'queue.connections.database.after_commit' => false,
    'queue.failed.driver' => 'database-uuids',
    'queue.failed.database' => 'sqlite',
    'cache.default' => 'file',
    'cache.stores.file.path' => $directory.'/cache',
    'cache.stores.file.lock_path' => $directory.'/cache',
    'logging.default' => 'single',
    'logging.channels.single.path' => $directory.'/worker.log',
]);
DB::purge('sqlite');
Cache::purge('file');
$app->forgetInstance('cache.store');
Http::preventStrayRequests();

if (($argv[2] ?? '') === 'inspect') {
    $failure = DB::table('failed_jobs')->sole();
    FetchPlayerInjuries::dispatch('22')->onConnection('database')->onQueue('injury-timeout-test');
    echo json_encode(['failed_count' => DB::table('failed_jobs')->count(), 'exception' => $failure->exception,
        'queued_count' => DB::table('jobs')->count()], JSON_THROW_ON_ERROR);
    exit(0);
}

(require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000002_create_jobs_table.php')->up();
$app->bind(SyncPlayerInjuries::class, fn () => new class(new EspnService) extends SyncPlayerInjuries
{
    public function execute(string $teamEspnId): int
    {
        sleep(8);

        return 0;
    }

    public function lastSyncReliable(): bool
    {
        return true;
    }
});
$job = new FetchPlayerInjuries('22');
$job->timeout = 1;
dispatch($job)->onConnection('database')->onQueue('injury-timeout-test');
Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'injury-timeout-test',
    '--stop-when-empty' => true, '--sleep' => 0, '--timeout' => 5, '--tries' => 3]);
