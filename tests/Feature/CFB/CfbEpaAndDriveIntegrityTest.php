<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\PlayEpaDataService;
use App\Services\CFB\TrueEpaCalculator;
use App\Services\Epa\StateBaselineService;
use Illuminate\Support\Facades\DB;

it('uses only prior state values and does not jump over special teams or halftime', function () {
    $baseline = Mockery::mock(StateBaselineService::class);
    $baseline->shouldReceive('getMap')->with('cfb', 2026)->andReturn(['1|7-10|71-80' => 2.0]);
    $service = new TrueEpaCalculator(app(PlayEpaDataService::class), $baseline);
    $make = fn ($id, $changes = []) => (object) [...['id' => $id, 'period' => 1, 'clock' => '10:00', 'play_type' => 'Rush', 'play_text' => 'Rush for 3 yards', 'down' => 1, 'distance' => 10,
        'yards_to_endzone' => 75, 'possession_team_id' => 1, 'home_score' => 0, 'away_score' => 0, 'is_scoring_play' => false], ...$changes];
    $a = $make(1);
    $punt = $make(2, ['play_type' => 'Punt', 'play_text' => 'Punt', 'down' => 4]);
    $b = $make(3, ['possession_team_id' => 2]);
    $result = $service->calculateForGame(collect([$a, $punt, $b]), 1, 2, 2026);
    expect($result[1]['ep_before'])->toBe(2.0)->and($result[1]['epa'])->toBeNull();
    $late = $make(1, ['period' => 2, 'clock' => '0:00']);
    $result = $service->calculateForGame(collect([$late, $make(2, ['period' => 3, 'home_score' => 99])]), 1, 2, 2026);
    expect($result[1]['ep_after'])->toBe(0.0)->and($result[1]['ep_before'])->toBe(2.0);
    $score = $make(1, ['is_scoring_play' => true, 'home_score' => 6]);
    expect($service->calculateForGame(collect([$score]), 1, 2, 2026)[1]['epa'])->toBe(4.0);
    $unknown = $make(1, ['distance' => 25]);
    expect($service->calculateForGame(collect([$unknown, $make(2, ['home_score' => 50])]), 1, 2, 2026)[1]['ep_before'])->toBeNull();
});

it('preserves a drive across quarters and reconciles a return touchdown and conversion', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_FINAL', 'home_score' => 7, 'away_score' => 0]);
    $hash = hash('sha256', 'drive-fixture');
    GameDataCheck::create(['game_id' => $game->id, 'component' => 'plays', 'source_hash' => $hash, 'validator_version' => '2', 'state' => 'complete',
        'discrepancies' => [], 'evidence' => [], 'source_path' => 'fixture', 'accepted_at' => now()]);
    $plays = [
        [1, '0:10', 'Rush', 'Rush for 1 yard', 0, 0, $away->id, false],
        [2, '14:50', 'Rush', 'Rush for 2 yards', 0, 0, $away->id, false],
        [2, '14:00', 'Punt', 'Punt', 0, 0, $away->id, false],
        [3, '15:00', 'Kickoff', 'Kickoff return touchdown', 6, 0, $away->id, true],
        [3, '15:00', 'Extra Point', 'Extra point good', 7, 0, $home->id, true],
        [4, '0:00', 'End of Game', 'End of Game', 7, 0, $home->id, false],
    ];
    foreach ($plays as $i => [$period,$clock,$type,$text,$hs,$as,$owner,$score]) {
        Play::create(['game_id' => $game->id, 'espn_play_id' => (string) $i, 'sequence_number' => $i,
            'period' => $period, 'clock' => $clock, 'play_type' => $type, 'play_text' => $text, 'home_score' => $hs, 'away_score' => $as, 'possession_team_id' => $owner,
            'is_scoring_play' => $score, 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 75, 'yards_gained' => 1, 'source_revision' => $hash]);
    }
    $builder = app(CfbDriveBuilder::class);
    $result = $builder->build($game);
    expect($result['state'])->toBe('complete')->and($result['drives'])->toBe(2);
    $rows = DB::table('cfb_drives')->where('game_id', $game->id)->orderBy('id')->get()->map(fn ($d) => json_decode($d->data, true));
    expect($rows[0]['plays'])->toBe(2)->and($rows[1]['offense_points'])->toBe(7)->and($rows[1]['kind'])->toBe('return');
    expect($builder->build($game, true)['reused'])->toBeTrue();
});
