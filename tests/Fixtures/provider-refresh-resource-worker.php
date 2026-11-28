<?php

use App\Services\ESPN\BaseEspnService;
use App\Services\ProviderData\Refresh\RefreshBudget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$mode = $argv[1] ?? '';
if ($mode === 'elevated-parent') {
    $padding = str_repeat('x', 140 * 1024 * 1024);
    $child = new Process([PHP_BINARY, '-d', 'memory_limit=128M', __FILE__, 'dense'], dirname(__DIR__, 2));
    $child->setTimeout(20)->mustRun();
    $result = json_decode($child->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    $result['parent_peak_bytes'] = memory_get_peak_usage(true);
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->beforeBootstrapping(LoadConfiguration::class, function ($app): void {
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Resource worker refuses cached configuration.');
    }
});
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    throw new RuntimeException('Resource worker requires explicit isolated SQLite testing configuration.');
}
Http::preventStrayRequests();
$budget = new RefreshBudget;
$limit = $budget->responseLimit();
if ($mode === 'dense') {
    $items = intdiv($limit - 16, 3);
    $body = '{"items":['.str_repeat('{},', $items - 1).'{}]}';
} elseif ($mode === 'admitted') {
    $body = '{"items":['.implode(',', array_fill(0, 2000, '{"value":0}')).']}';
} else {
    throw new RuntimeException('Unknown resource scenario.');
}
Http::fake(['https://sports.core.api.espn.com/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
$service = new BaseEspnService('nfl');
$service->swapRefreshBudget($budget);
$payload = $service->getByRef('https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/injuries/1', false);
if ($mode === 'admitted') {
    $budget->rows(count($payload['items']));
}
echo json_encode(['mode' => $mode, 'raw_bytes' => strlen($body), 'response_limit' => $limit,
    'rejected' => $payload === null, 'count' => isset($payload['items']) ? count($payload['items']) : null,
    'last_value' => $payload['items'][1999]['value'] ?? null, 'sent_count' => Http::recorded()->count(),
    'peak_bytes' => memory_get_peak_usage(true), 'memory_limit' => ini_get('memory_limit')], JSON_THROW_ON_ERROR);
