<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\ESPN\CBB\EspnService;
use App\Models\CBB\Game;

$service = new EspnService;

// Get scoreboard event IDs first (same as the sync does)
$response = $service->getScoreboard('20260217');
$scoreboardEventIds = collect($response['events'] ?? [])->pluck('id')->map(fn($id) => (string)$id)->toArray();
echo "Scoreboard events: " . count($scoreboardEventIds) . "\n";

// Find orphaned games (same query as sync)
$orphaned = Game::query()
    ->whereIn('status', ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'])
    ->whereNotIn('espn_event_id', $scoreboardEventIds)
    ->get();

echo "Orphaned games found: " . $orphaned->count() . "\n\n";

// Test first 10
foreach ($orphaned->take(10) as $game) {
    echo "{$game->short_name} ({$game->game_date}) ESPN:{$game->espn_event_id} ... ";
    $data = $service->getGame($game->espn_event_id);
    if (!$data) {
        echo "NULL RESPONSE\n";
    } else {
        $comp = $data['header']['competitions'][0] ?? [];
        $status = $comp['status']['type']['name'] ?? 'MISSING';
        $home = collect($comp['competitors'] ?? [])->firstWhere('homeAway', 'home');
        $away = collect($comp['competitors'] ?? [])->firstWhere('homeAway', 'away');
        echo "{$status} | " . ($away['score'] ?? '?') . "-" . ($home['score'] ?? '?') . "\n";
    }
    usleep(500000);
}
