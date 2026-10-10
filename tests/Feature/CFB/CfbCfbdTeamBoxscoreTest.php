<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;
use App\Models\ProviderSourceFile;
use App\Services\CFB\Data\CfbCfbdTeamBoxscoreSync;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('cfb.data.source_disk', 'local');
    $home = Team::factory()->create(['cfbd_team_id' => 11]);
    $away = Team::factory()->create(['cfbd_team_id' => 12]);
    $this->game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 14, 'away_score' => 7]);
    GameDataCheck::create(['game_id' => $this->game->id, 'component' => 'boxscore', 'validator_version' => '2',
        'source_hash' => hash('sha256', 'rejected-espn'), 'state' => 'partial', 'source_path' => 'espn.json',
        'discrepancies' => ['team_yards_do_not_reconcile'], 'evidence' => []]);
    $stats = ['totalYards' => '100', 'netPassingYards' => '75', 'rushingYards' => '25',
        'completionAttempts' => '5-10', 'rushingAttempts' => '10', 'firstDowns' => '7',
        'thirdDownEff' => '1-2', 'fourthDownEff' => '0-1', 'totalPenaltiesYards' => '1-5', 'sacks' => '2'];
    $make = fn ($id, $side, $points) => ['teamId' => $id, 'homeAway' => $side, 'points' => $points,
        'stats' => collect($stats)->map(fn ($value, $key) => ['category' => $key, 'stat' => $value])->values()->all()];
    $this->payload = ['id' => (int) $this->game->espn_event_id,
        'teams' => [$make(11, 'home', 14), $make(12, 'away', 7)]];
});

it('imports a proven secondary team box while keeping player and play quarantine', function () {
    $this->mock(CollegeFootballDataService::class)->shouldReceive('get')->once()->andReturn([$this->payload]);
    expect(app(CfbDataReadiness::class)->coreBoxscoreReady($this->game))->toBeFalse();
    $result = app(CfbCfbdTeamBoxscoreSync::class)->execute($this->game);
    expect($result['state'])->toBe('complete')
        ->and(TeamStat::where('game_id', $this->game->id)->count())->toBe(2)
        ->and(app(CfbDataReadiness::class)->coreBoxscoreReady($this->game))->toBeTrue()
        ->and(app(CfbDataReadiness::class)->forGame($this->game)['ready'])->toBeFalse()
        ->and(GameDataCheck::where('component', 'boxscore')->value('accepted_at'))->toBeNull()
        ->and(ProviderSourceFile::where('provider', 'cfbd')->exists())->toBeTrue();
    $this->game->update(['home_score' => 15]);
    expect(app(CfbDataReadiness::class)->coreBoxscoreReady($this->game))->toBeFalse();
});

it('rejects a secondary box with wrong teams scores or inconsistent yards before replacing any data', function (string $fault) {
    $payload = $this->payload;
    if ($fault === 'team') {
        $payload['teams'][0]['teamId'] = 999;
    } elseif ($fault === 'score') {
        $payload['teams'][0]['points'] = 15;
    } else {
        $payload['teams'][0]['stats'][0]['stat'] = '99';
    }
    $this->mock(CollegeFootballDataService::class)->shouldReceive('get')->andReturn([$payload]);
    expect(fn () => app(CfbCfbdTeamBoxscoreSync::class)->execute($this->game))->toThrow(RuntimeException::class)
        ->and(TeamStat::where('game_id', $this->game->id)->count())->toBe(0)
        ->and(GameDataCheck::whereNotNull('accepted_at')->count())->toBe(0);
})->with(['team', 'score', 'yards']);

it('invalidates secondary evidence when imported stats change', function () {
    $this->mock(CollegeFootballDataService::class)->shouldReceive('get')->andReturn([$this->payload]);
    app(CfbCfbdTeamBoxscoreSync::class)->execute($this->game);
    TeamStat::where('game_id', $this->game->id)->where('team_id', $this->game->home_team_id)->update(['passing_yards' => 76]);
    expect(app(CfbDataReadiness::class)->coreBoxscoreReady($this->game))->toBeFalse();
});
