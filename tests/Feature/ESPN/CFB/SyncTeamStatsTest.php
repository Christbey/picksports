<?php

use App\Actions\ESPN\CFB\SyncTeamStats;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;

beforeEach(function () {
    $this->home = Team::factory()->create(['espn_id' => '2390']);
    $this->away = Team::factory()->create(['espn_id' => '50']);
    $this->game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id]);
    $this->payload = ['boxscore' => [
        'teams' => [
            ['team' => ['id' => '50'], 'statistics' => []],
            ['team' => ['id' => '2390'], 'statistics' => []],
        ],
        'players' => [
            ['team' => ['id' => '2390'], 'statistics' => [
                ['name' => 'defensive', 'keys' => ['totalTackles', 'soloTackles', 'sacks'], 'totals' => ['40', '22', '2']],
            ]],
            ['team' => ['id' => '50'], 'statistics' => [
                ['name' => 'defensive', 'keys' => ['sacks', 'totalTackles'], 'totals' => ['0', '68']],
            ]],
        ],
    ]];
});

it('stores opponent aggregate defensive sacks as sacks allowed including explicit zero', function () {
    expect(app(SyncTeamStats::class)->execute($this->payload, $this->game))->toBe(2);
    $stats = TeamStat::query()->where('game_id', $this->game->id)->get()->keyBy('team_id');
    expect($stats[$this->home->id]->sacks_allowed)->toBe(0)
        ->and($stats[$this->away->id]->sacks_allowed)->toBe(2);
});

it('preserves direct team sacks and leaves absent or invalid aggregates unknown', function ($missing) {
    $this->payload['boxscore']['teams'][0]['statistics'][] = ['name' => 'sacksYardsLost', 'displayValue' => '3-19'];
    $this->payload['boxscore']['players'][1]['statistics'][0]['totals'][0] = $missing;
    app(SyncTeamStats::class)->execute($this->payload, $this->game);
    $stats = TeamStat::query()->where('game_id', $this->game->id)->get()->keyBy('team_id');
    expect($stats[$this->home->id]->sacks_allowed)->toBeNull()
        ->and($stats[$this->away->id]->sacks_allowed)->toBe(3);
})->with([null, '--', '-1', '1.5']);
