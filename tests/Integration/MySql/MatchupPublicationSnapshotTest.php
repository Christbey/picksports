<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\MatchupSamplePublication;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.matchup_independent' => config('database.connections.mysql')]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $this->game = Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    DB::table('nflverse_pbp_plays')->insert(['nflverse_play_key' => 'snapshot-join', 'nflverse_game_id' => '2026_01_A_B',
        'nfl_game_id' => $this->game->id, 'season' => 2026, 'week' => 1, 'possession_team' => 'A', 'possession_team_id' => $home->id,
        'defense_team' => 'B', 'defense_team_id' => $away->id]);
    $this->sample = ['game_id' => $this->game->id, 'team_id' => $home->id, 'season' => 2026, 'metric' => 'release_time',
        'player_id' => 'gsis:00-0000001', 'value' => 2.5, 'sample_size' => 20, 'observed_at' => now()->toDateTimeString()];
    DB::table('nfl_matchup_tracking_samples')->insert($this->sample);
});

afterEach(function () {
    foreach ([DB::connection(), DB::connection('matchup_independent')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('matchup_independent');
});

it('uses current reads for source joins and prior identities behind a pinned snapshot', function (string $change) {
    $reader = $change === 'named_source' ? DB::connection('matchup_independent') : DB::connection();
    $writer = $change === 'named_source' ? DB::connection() : DB::connection('matchup_independent');
    $publication = app(MatchupSamplePublication::class);
    $snapshot = $publication->snapshot($reader, 2026, false);
    $reader->beginTransaction();
    $reader->table('nfl_matchup_tracking_samples')->first();
    if ($change === 'prior_identity') {
        $writer->table('nfl_matchup_tracking_samples')->insert([...$this->sample, 'player_id' => 'gsis:00-0000002']);
    } else {
        $writer->table('nflverse_pbp_plays')->update(['possession_team' => 'changed']);
    }
    $before = $writer->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson();
    expect(fn () => $publication->publish($reader, 'nfl_matchup_tracking_samples', 2026, [$this->sample], $snapshot))->toThrow(RuntimeException::class);
    $reader->rollBack();
    expect($writer->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson())->toBe($before);
})->with(['source', 'prior_identity', 'named_source']);
