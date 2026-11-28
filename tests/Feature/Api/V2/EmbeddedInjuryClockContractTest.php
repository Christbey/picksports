<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

it('keeps embedded injury source clocks unqualified without losing raw evidence or observed zeros', function (?string $clock) {
    config(['subscriptions.enforce_tiers' => false]);
    Sanctum::actingAs(User::factory()->create());
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $player = Player::factory()->create(['team_id' => $home->id, 'full_name' => 'Synthetic Injury Player']);
    $injury = PlayerInjury::create([
        'team_id' => $home->id, 'player_id' => $player->id, 'injury_key' => 'synthetic-clock',
        'status' => 'Out', 'detail' => '0', 'source_updated_at' => $clock, 'is_active' => true,
    ]);
    $before = $injury->fresh()->getRawOriginal();
    foreach ([
        ["/api/v2/sports/nfl/games/{$game->id}/page", 'data.game.home_team.active_injuries.0'],
        ["/api/v2/sports/nfl/players/{$player->id}", 'data.active_injuries.0'],
    ] as [$url, $path]) {
        $this->getJson($url)->assertSuccessful()
            ->assertJsonPath("{$path}.source_updated_at", null)
            ->assertJsonPath("{$path}.source_updated_at_raw", $clock)
            ->assertJsonPath("{$path}.source_clock_status", 'unverified_storage_clock')
            ->assertJsonPath("{$path}.detail", '0');
    }
    expect($injury->fresh()->getRawOriginal())->toBe($before);
})->with(['stored clock' => ['2026-11-26 22:00:00'], 'missing clock' => [null]]);

it('declares embedded injury raw and unknown clocks without widening observation instants', function () {
    expect(Artisan::call('api:v2-openapi-generate', ['--stdout' => true]))->toBe(0);
    $spec = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $fields = $spec['components']['schemas']['SportPlayer']['properties']['active_injuries']['items']['properties'];
    expect($fields['source_updated_at']['type'])->toBe('null')
        ->and($fields['source_updated_at_raw']['type'])->toBe(['string', 'null'])
        ->and($fields['source_clock_status']['enum'])->toBe(['unverified_storage_clock']);
});
