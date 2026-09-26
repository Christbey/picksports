<?php

use App\Actions\ESPN\CFB\SyncGameDetails;
use App\Actions\ESPN\CFB\SyncPlayerStats;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceRevision;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Fixtures/cfb_complete_box.php';

it('resolves unknown source athletes and retains team credits without fake players', function () {
    Storage::fake('local');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $payload = cfbCompleteBox($game);
    expect(app(CfbGameDataValidator::class)->boxscore($payload, $game)['state'])->toBe('complete');
    app(SyncPlayerStats::class)->execute($payload, $game);
    expect(Player::whereIn('espn_id', ['90001', '90002'])->count())->toBe(2)
        ->and(Player::where('espn_id', '-1')->exists())->toBeFalse()
        ->and(PlayerStat::where('game_id', $game->id)->sum('passing_yards'))->toBe(600);
    app(SyncPlayerStats::class)->execute($payload, $game);
    expect(PlayerStat::where('game_id', $game->id)->count())->toBe(2);
});

it('preserves accepted rows when a candidate boxscore loses an athlete or is empty', function () {
    Storage::fake('local');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $payload = cfbCompleteBox($game);
    app(SyncPlayerStats::class)->execute($payload, $game);
    $payload['boxscore']['players'][0]['statistics'][0]['athletes'][0]['stats'][1] = '39';
    expect(app(SyncPlayerStats::class)->execute($payload, $game))->toBe(0);
    expect(app(SyncPlayerStats::class)->execute(['boxscore' => ['players' => []]], $game))->toBe(0);
    expect(PlayerStat::where('game_id', $game->id)->sum('passing_yards'))->toBe(600);
});

it('does not change present team affiliation when importing an old game', function () {
    Storage::fake('local');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $current = Team::factory()->create();
    $player = Player::factory()->create(['espn_id' => '90001', 'team_id' => $current->id]);
    app(SyncPlayerStats::class)->execute(cfbCompleteBox($game), $game);
    expect($player->fresh()->team_id)->toBe($current->id)
        ->and(PlayerStat::where('game_id', $game->id)->where('player_id', $player->id)->first()->team_id)->toBe($game->home_team_id);
});

it('requires pagination evidence and score reconciliation without an arbitrary play-count threshold', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'status' => 'STATUS_FINAL', 'home_score' => 7, 'away_score' => 0]);
    $payload = ['count' => 1, 'items' => [['id' => '1', 'text' => 'End of Game', 'period' => ['number' => 4], 'clock' => ['displayValue' => '0:00'], 'homeScore' => 7, 'awayScore' => 0]]];
    $validator = app(CfbGameDataValidator::class);
    expect($validator->plays($payload, $game)['state'])->toBe('complete');
    $payload['count'] = 2;
    expect($validator->plays($payload, $game)['discrepancies'])->toContain('unproven_pagination_completeness');
});

it('recovers readiness when a previously accepted response follows a transient partial response', function () {
    Storage::fake('local');
    $this->travelTo(now()->startOfDay());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $archive = app(CfbSourceRevision::class);
    $valid = ['state' => 'complete', 'discrepancies' => [], 'evidence' => []];
    foreach (['boxscore', 'plays'] as $component) {
        $archive->record($game, $component, ['valid' => true], $valid)->update(['accepted_at' => now()]);
    }
    $this->travel(1)->minutes();
    $archive->record($game, 'boxscore', [], ['state' => 'partial', 'discrepancies' => ['empty'], 'evidence' => []]);
    $readiness = app(CfbDataReadiness::class);
    expect($readiness->forGame($game)['ready'])->toBeFalse();
    $this->travel(1)->minutes();
    $archive->record($game, 'boxscore', ['valid' => true], $valid);
    expect($readiness->forGame($game)['ready'])->toBeTrue();
});

it('resolves the real college football dependencies for the production repair command', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'status' => 'STATUS_FINAL']);
    expect(app(SyncGameDetails::class))->toBeInstanceOf(SyncGameDetails::class);
    $this->artisan('cfb:repair-game-data', ['--game' => $game->id, '--max-games' => 1, '--dry-run' => true])->assertSuccessful();
});
