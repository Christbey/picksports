<?php

use App\Models\NBA\Player;
use App\Models\NBA\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

test('sports assets sync command mirrors player headshots under sport and team directories', function () {
    config()->set('sports_assets.disk', 'public');
    config()->set('sports_assets.directory', 'sports');
    config()->set('sports_assets.mirror', true);

    Storage::fake('public');

    $team = Team::factory()->create([
        'espn_id' => '1',
        'location' => 'Atlanta',
        'name' => 'Hawks',
    ]);

    $player = Player::factory()->create([
        'team_id' => $team->id,
        'espn_id' => '23',
        'first_name' => 'Trae',
        'last_name' => 'Young',
        'headshot_url' => 'https://example.com/player-23.png',
    ]);

    Http::fake([
        'https://example.com/player-23.png' => Http::response('fake-headshot', 200, ['Content-Type' => 'image/png']),
    ]);

    artisan('sports-assets:sync nba --type=players')->assertSuccessful();

    $player->refresh();

    expect($player->getRawOriginal('headshot_url'))->toBe('sports/nba/teams/atlanta-hawks-1/players/trae-young-23/headshot.png')
        ->and($player->headshot_url)->toContain('/storage/sports/nba/teams/atlanta-hawks-1/players/trae-young-23/headshot.png');

    Storage::disk('public')->assertExists('sports/nba/teams/atlanta-hawks-1/players/trae-young-23/headshot.png');
});
