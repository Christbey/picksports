<?php

use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Models\SportEvent;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

test('v2 research preserves field permission checks independently of sport API access', function (string $missingPermission) {
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    $user = User::factory()->create();
    $permissions = ['access-api', 'view-nfl-predictions', 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        if ($permission !== $missingPermission) {
            $user->givePermissionTo($permission);
        }
    }
    Sanctum::actingAs($user);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/research")->assertForbidden();
})->with(['access-api', 'view-nfl-predictions', 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value']);

test('v2 research resolves only nfl games and returns the latest twenty revisions', function () {
    config(['subscriptions.enforce_tiers' => false]);
    Sanctum::actingAs(User::factory()->create());
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    for ($i = 0; $i < 21; $i++) {
        $revision = ResearchRevision::create([
            'game_id' => $game->id, 'input_hash' => hash('sha256', (string) $i),
            'baseline' => ['predicted_spread' => 3], 'revised' => ['predicted_spread' => 4],
            'brief' => ['supporting' => []], 'market' => [], 'evidence' => [], 'created_at' => now(),
        ]);
    }
    foreach ([$game->id, $event->public_id] as $id) {
        $this->getJson("/api/v2/sports/nfl/games/{$id}/research")
            ->assertOk()->assertJsonPath('data.game_id', $game->id)
            ->assertJsonCount(20, 'data.revisions')
            ->assertJsonPath('data.revisions.0.id', $revision->id)
            ->assertJsonMissingPath('data.revisions.0.input_hash')
            ->assertJsonPath('meta.contract', 'sports.games.research.show');
    }
    $this->getJson("/api/v2/sports/cfb/games/{$game->id}/research")->assertNotFound();
    $this->getJson('/api/v2/sports/nfl/games/not-a-game/research')->assertNotFound();
    $other = SportEvent::factory()->create(['sport' => 'cfb']);
    $this->getJson("/api/v2/sports/nfl/games/{$other->public_id}/research")->assertNotFound();
});
