<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbScoringLedger;
use App\Services\CFB\PlayEpaDataService;
use App\Services\CFB\TrueEpaCalculator;
use App\Services\Epa\StateBaselineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function cfbLedgerFixture(array $changes = []): array
{
    Storage::fake('local');
    config()->set('cfb.data.source_disk', 'local');
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 7, 'away_score' => 0]);
    $payload = ['header' => ['id' => $game->espn_event_id, 'competitions' => [['status' => ['type' => ['name' => 'STATUS_FINAL']],
        'competitors' => [['homeAway' => 'home', 'team' => ['id' => $home->espn_id], 'score' => '7'],
            ['homeAway' => 'away', 'team' => ['id' => $away->espn_id], 'score' => '0']]]]],
        'scoringPlays' => [['id' => 'td', 'homeScore' => 7, 'awayScore' => 0, 'team' => ['id' => $home->espn_id]]]];
    $payload = array_replace_recursive($payload, $changes);
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $hash = hash('sha256', $json);
    Storage::disk('local')->put('ledger.json', $json);
    GameDataCheck::create(['game_id' => $game->id, 'component' => 'boxscore', 'source_hash' => $hash,
        'validator_version' => '2', 'state' => 'complete', 'discrepancies' => [], 'evidence' => [],
        'source_path' => 'ledger.json', 'accepted_at' => now()]);
    $playHash = hash('sha256', 'ledger-plays');
    GameDataCheck::create(['game_id' => $game->id, 'component' => 'plays', 'source_hash' => $playHash,
        'validator_version' => '2', 'state' => 'complete', 'discrepancies' => [], 'evidence' => [],
        'source_path' => 'plays.json', 'accepted_at' => now()]);
    $plays = collect([
        ['rush', 'Rush', 'Rush for 2 yards', 0],
        ['td', 'Rushing Touchdown', 'Rush touchdown, extra point good', 7],
        ['penalty', 'Penalty', 'False start NO PLAY', 6],
        ['kickoff', 'Kickoff', 'Kickoff touchback', 14],
        ['next', 'Pass Incompletion', 'Pass incomplete', 7],
    ])->map(function ($row, $i) use ($game, $home, $playHash) {
        return Play::create(['game_id' => $game->id, 'espn_play_id' => $row[0], 'sequence_number' => $i,
            'period' => 1, 'clock' => '10:00', 'play_type' => $row[1], 'play_text' => $row[2],
            'home_score' => $row[3], 'away_score' => 0, 'possession_team_id' => $home->id,
            'is_scoring_play' => $row[0] === 'td', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 75,
            'yards_gained' => 2, 'source_revision' => $playHash]);
    });

    return [$game, $plays];
}

it('reconciles premature and rolled back snapshots from the verified scoring ledger without editing raw plays', function () {
    [$game, $plays] = cfbLedgerFixture();
    $normalized = app(CfbScoringLedger::class)->normalize($game, $plays);
    expect($normalized->pluck('home_score')->all())->toBe([0, 7, 7, 7, 7])
        ->and($plays->pluck('home_score')->all())->toBe([0, 7, 6, 14, 7])
        ->and(Play::where('game_id', $game->id)->where('espn_play_id', 'kickoff')->value('home_score'))->toBe(14);
    $result = app(CfbDriveBuilder::class)->build($game);
    expect($result['state'])->toBe('complete');
    $first = json_decode(DB::table('cfb_drives')->where('game_id', $game->id)->orderBy('id')->value('data'), true);
    expect($first['plays'])->toBe(2)->and($first['offense_points'])->toBe(7);
});

it('holds a ledger that cannot prove each final score or link every scoring play', function (array $changes) {
    [$game, $plays] = cfbLedgerFixture($changes);
    expect(fn () => app(CfbScoringLedger::class)->normalize($game, $plays))->toThrow(RuntimeException::class)
        ->and(app(CfbDriveBuilder::class)->build($game)['state'])->toBe('blocked');
})->with([
    'missing scoring play' => [['scoringPlays' => [['id' => 'missing']]]],
    'wrong final score' => [['scoringPlays' => [['homeScore' => 6]]]],
    'wrong event' => [['header' => ['id' => 'other']]],
    'wrong scoring team' => [['scoringPlays' => [['team' => ['id' => 'other']]]]],
]);

it('rejects a changed scoring archive even if its final score still agrees', function () {
    [$game, $plays] = cfbLedgerFixture();
    Storage::disk('local')->put('ledger.json', '{}');
    expect(fn () => app(CfbScoringLedger::class)->normalize($game, $plays))->toThrow(RuntimeException::class, 'source hash mismatch');
});

it('rebuilds a verified game through the isolated normalization command', function () {
    [$game] = cfbLedgerFixture();
    $this->artisan('cfb:normalize-game', ['--game' => $game->id])->assertSuccessful();
    expect(DB::table('cfb_drives')->where('game_id', $game->id)->where('quality', 'complete')->exists())->toBeTrue();
});

it('retains a ledger verified touchdown when its conversion description contains a no play penalty', function () {
    [$game, $plays] = cfbLedgerFixture();
    $plays[1]->update(['play_text' => 'Rush touchdown, kick attempt good PENALTY Illegal Snap. NO PLAY']);
    $result = app(CfbDriveBuilder::class)->build($game);
    expect($result['state'])->toBe('complete');
    $drives = DB::table('cfb_drives')->where('game_id', $game->id)->get();
    expect($drives->sum(fn ($drive) => json_decode($drive->data, true)['offense_points']))->toBe(7);
});

it('attributes a ledger verified safety to the opposing offense when possession is absent', function () {
    [$game, $plays] = cfbLedgerFixture(['header' => ['competitions' => [['competitors' => [['score' => '2']]]]],
        'scoringPlays' => [['homeScore' => 2]]]);
    $game->update(['home_score' => 2]);
    $plays[1]->update(['play_type' => 'Safety', 'play_text' => 'Team Safety', 'possession_team_id' => null]);
    $result = app(CfbDriveBuilder::class)->build($game);
    expect($result['state'])->toBe('complete');
    $drives = DB::table('cfb_drives')->where('game_id', $game->id)->get()
        ->map(fn ($drive) => json_decode($drive->data, true));
    $safety = $drives->first(fn ($drive) => $drive['opponent_points'] === 2);
    expect($safety['offense_team_id'])->toBe($game->away_team_id)
        ->and($safety['offense_points'])->toBe(0);
});

it('keeps an unverified score on a no play description held', function () {
    [$game, $plays] = cfbLedgerFixture();
    GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')->delete();
    $plays[1]->update(['play_text' => 'Touchdown PENALTY. NO PLAY']);
    expect(app(CfbDriveBuilder::class)->build($game)['state'])->toBe('partial');
});

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
