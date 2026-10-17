<?php

use App\Actions\ESPN\CFB\SyncPlays;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Models\EpaStateBaseline;
use App\Services\CFB\CfbPlayEpaWriter;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbScoringLedger;
use App\Services\CFB\PlayEpaDataService;
use App\Services\CFB\TrueEpaCalculator;
use App\Services\Epa\StateBaselineService;
use App\Services\ESPN\CFB\EspnService;
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

it('reconciles contradictory intermediate scores only when every explicit scoring event proves both final totals', function () {
    [$game, $plays] = cfbLedgerFixture();
    $game->update(['away_score' => 3]);
    $check = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')->firstOrFail();
    $payload = json_decode(Storage::disk('local')->get($check->source_path), true);
    $payload['header']['competitions'][0]['competitors'][1]['score'] = '3';
    $payload['scoringPlays'] = [
        ['id' => 'td', 'homeScore' => 7, 'awayScore' => 3, 'team' => ['id' => $game->homeTeam->espn_id],
            'type' => ['text' => 'Rushing Touchdown'], 'text' => 'Rush touchdown (A. Kicker Kick)'],
        ['id' => 'kickoff', 'homeScore' => 7, 'awayScore' => 3, 'team' => ['id' => $game->awayTeam->espn_id],
            'type' => ['text' => 'Field Goal Good'], 'text' => '30 Yd Field Goal'],
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($check->source_path, $json);
    $check->update(['source_hash' => hash('sha256', $json)]);
    $normalized = app(CfbScoringLedger::class)->normalize($game, $plays);
    expect($normalized->pluck('away_score')->all())->toBe([0, 0, 0, 3, 3])
        ->and($normalized->pluck('home_score')->all())->toBe([0, 7, 7, 7, 7]);
    $payload['scoringPlays'][0]['text'] = 'Rush touchdown (Kick Failed)';
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($check->source_path, $json);
    $check->update(['source_hash' => hash('sha256', $json)]);
    expect(fn () => app(CfbScoringLedger::class)->normalize($game, $plays))->toThrow(RuntimeException::class);
});

it('rebuilds a verified game through the isolated normalization command', function () {
    [$game] = cfbLedgerFixture();
    $this->artisan('cfb:normalize-game', ['--game' => $game->id])->assertSuccessful();
    expect(DB::table('cfb_drives')->where('game_id', $game->id)->where('quality', 'complete')->exists())->toBeTrue();
});

it('proves an omitted conversion only from complete successful PAT counts for every touchdown', function () {
    [$game, $plays] = cfbLedgerFixture();
    $box = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')->firstOrFail();
    $payload = json_decode(Storage::disk('local')->get($box->source_path), true);
    $payload['scoringPlays'][0] += ['type' => ['text' => 'Rushing Touchdown'], 'text' => 'Rush for a TD'];
    $payload['scoringPlays'][0]['homeScore'] = 6;
    $kicker = ['athlete' => ['id' => '123'], 'stats' => ['1/1', '0/0', '1']];
    $payload['boxscore']['players'] = [['team' => ['id' => $game->homeTeam->espn_id], 'statistics' => [
        ['name' => 'kicking', 'labels' => ['XP', 'FG', 'PTS'], 'athletes' => [$kicker]],
    ]]];
    $save = function (array $source) use ($box) {
        $json = json_encode($source, JSON_THROW_ON_ERROR);
        Storage::disk('local')->put($box->source_path, $json);
        $box->update(['source_hash' => hash('sha256', $json)]);
    };
    $save($payload);
    expect(app(CfbScoringLedger::class)->normalize($game, $plays)->last()->home_score)->toBe(7);
    foreach ([['0/1', '0/0', '0'], ['1/1', '0/0', '2'], ['1/2', '0/0', '1'], ['1/1']] as $stats) {
        $bad = $payload;
        $bad['boxscore']['players'][0]['statistics'][0]['athletes'][0]['stats'] = $stats;
        $save($bad);
        expect(fn () => app(CfbScoringLedger::class)->normalize($game, $plays))->toThrow(RuntimeException::class);
    }
    $bad = $payload;
    $bad['boxscore']['players'][0]['statistics'][0]['athletes'][] = $kicker;
    $save($bad);
    expect(fn () => app(CfbScoringLedger::class)->normalize($game, $plays))->toThrow(RuntimeException::class);
});

it('reconciles overtime touchdowns and observed failed conversions without trusting simultaneous score snapshots', function () {
    [$game] = cfbLedgerFixture();
    $game->update(['home_score' => 41, 'away_score' => 43]);
    $box = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')->firstOrFail();
    $payload = json_decode(Storage::disk('local')->get($box->source_path), true);
    $payload['header']['competitions'][0]['competitors'][0]['score'] = '41';
    $payload['header']['competitions'][0]['competitors'][1]['score'] = '43';
    $sides = ['away', 'home', 'home', 'away', 'home', 'away', 'away', 'home', 'away', 'home', 'home', 'away', 'away'];
    $payload['scoringPlays'] = collect($sides)->map(fn ($side, $index) => [
        'id' => (string) $index, 'team' => ['id' => $game->{$side.'Team'}->espn_id],
        'homeScore' => 41, 'awayScore' => 43,
        'type' => ['text' => $index === 12 ? 'Two Point Pass' : 'Rushing Touchdown'],
        'text' => $index === 12 ? 'Pass for Two-Point Conversion' : ($index >= 10
            ? 'Touchdown (Two-Point Pass Conversion Failed)' : 'Touchdown (A Kicker Kick)'),
    ])->all();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($box->source_path, $json);
    $box->update(['source_hash' => hash('sha256', $json)]);
    $plays = collect($sides)->map(fn ($side, $index) => new Play(['espn_play_id' => (string) $index,
        'home_score' => 41, 'away_score' => 43]));
    $normalized = app(CfbScoringLedger::class)->normalize($game, $plays);
    expect($normalized[8]->home_score)->toBe(28)->and($normalized[8]->away_score)->toBe(35)
        ->and($normalized->last()->home_score)->toBe(41)->and($normalized->last()->away_score)->toBe(43);
    $payload['scoringPlays'][12]['text'] .= '. NO PLAY';
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($box->source_path, $json);
    $box->update(['source_hash' => hash('sha256', $json)]);
    expect(fn () => app(CfbScoringLedger::class)->normalize($game, $plays))->toThrow(RuntimeException::class);
});

it('certifies stale terminal score snapshots only with a complete terminal source and a matching verified ledger', function () {
    [$game] = cfbLedgerFixture();
    $payload = ['count' => 2, 'items' => [
        ['id' => 'td', 'homeScore' => 7, 'awayScore' => 0, 'type' => ['text' => 'Rushing Touchdown'],
            'text' => 'Touchdown', 'period' => ['number' => 1], 'clock' => ['displayValue' => '10:00']],
        ['id' => 'end', 'homeScore' => 6, 'awayScore' => 0, 'type' => ['text' => 'End of Game'],
            'text' => 'End of Game', 'period' => ['number' => 4], 'clock' => ['displayValue' => '0:00']],
    ]];
    $validator = app(CfbGameDataValidator::class);
    $result = $validator->plays($payload, $game);
    expect($result['state'])->toBe('complete')
        ->and($result['evidence']['score_snapshot_reconciliation']['raw_score_snapshots_preserved'])->toBeTrue();
    $source = Mockery::mock(EspnService::class);
    $source->shouldReceive('getPlays')->twice()->andReturn($payload);
    $sync = new SyncPlays($source);
    expect($sync->execute((string) $game->espn_event_id))->toBe(2)
        ->and(Play::where('game_id', $game->id)->where('espn_play_id', 'end')->value('home_score'))->toBe(6)
        ->and(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeTrue();
    $box = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')->firstOrFail();
    $json = json_decode(Storage::disk('local')->get($box->source_path), true);
    $json['revision'] = 'corrected';
    $json = json_encode($json, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($box->source_path, $json);
    $box->update(['source_hash' => hash('sha256', $json)]);
    Play::where('game_id', $game->id)->where('espn_play_id', 'td')->update(['true_epa' => 1.25, 'epa_calculated_at' => now()]);
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeFalse()
        ->and($sync->execute((string) $game->espn_event_id))->toBe(0)
        ->and((float) Play::where('game_id', $game->id)->where('espn_play_id', 'td')->value('true_epa'))->toBe(1.25)
        ->and(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeTrue();
    expect(GameDataCheck::where('game_id', $game->id)->where('component', 'plays')
        ->where('validator_version', 'like', CfbGameDataValidator::RECONCILED_PLAYS_VERSION.':%')->count())->toBe(2);
    $payload['count'] = 3;
    expect($validator->plays($payload, $game)['state'])->toBe('partial');
    $payload['count'] = 2;
    $payload['items'][1]['text'] = 'Rush';
    $payload['items'][1]['clock']['displayValue'] = '1:00';
    expect($validator->plays($payload, $game)['state'])->toBe('partial');
});

it('retains a ledger verified touchdown when its conversion description contains a no play penalty', function () {
    [$game, $plays] = cfbLedgerFixture();
    $plays[1]->update(['play_text' => 'Rush touchdown, kick attempt good PENALTY Illegal Snap. NO PLAY']);
    $normalized = app(CfbScoringLedger::class)->normalize($game, $plays);
    expect(app(PlayEpaDataService::class)->isEpaEligiblePlay($normalized[1]))->toBeTrue()
        ->and(app(PlayEpaDataService::class)->isEpaEligiblePlay($plays[1]))->toBeFalse();
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

it('does not turn alternating provider references into extra possessions', function () {
    [$game, $plays] = cfbLedgerFixture();
    $plays[0]->update(['source_state' => ['drive' => ['$ref' => 'provider/drive/1']]]);
    $plays[1]->update(['source_state' => ['drive' => ['$ref' => 'provider/drive/2']]]);
    $result = app(CfbDriveBuilder::class)->build($game);
    expect($result['state'])->toBe('complete')->and($result['normalizer_version'])->toBe('5');
    $first = json_decode(DB::table('cfb_drives')->where('game_id', $game->id)->orderBy('id')->value('data'), true);
    expect($first['plays'])->toBe(2)->and($first['offense_points'])->toBe(7);
});

it('trains past-season EPA states from the accepted revision and reconciled scoring ledger', function () {
    [$game, $plays] = cfbLedgerFixture();
    $game->update(['season' => 2025]);
    $plays[3]->update(['down' => 0]);
    Play::create(['game_id' => $game->id, 'espn_play_id' => 'unaccepted', 'sequence_number' => 0,
        'period' => 1, 'clock' => '15:00', 'play_type' => 'Rush', 'play_text' => 'Rush for 2 yards',
        'home_score' => 99, 'away_score' => 0, 'possession_team_id' => $game->home_team_id,
        'down' => 1, 'distance' => 10, 'yards_to_endzone' => 75, 'yards_gained' => 2, 'source_revision' => hash('sha256', 'unaccepted')]);
    $this->mock(CfbDataReadiness::class)->shouldReceive('forGame')->once()->andReturn(['ready' => true]);
    $this->artisan('cfb:train-epa-baseline', ['--season' => 2026, '--source-season' => 2025, '--minimum-state-samples' => 1])->assertSuccessful();
    $state = EpaStateBaseline::where('sport', 'cfb')->where('season', 2026)->firstOrFail();
    expect($state->sample_size)->toBe(3)->and((float) $state->expected_points)->toEqualWithDelta(14 / 3, .0001)
        ->and($state->source_season)->toBe(2025);
});

it('does not recalculate EPA for a play outside the accepted source revision', function () {
    [$game, $plays] = cfbLedgerFixture();
    $unaccepted = Play::create(['game_id' => $game->id, 'espn_play_id' => 'unaccepted-epa', 'sequence_number' => 0,
        'period' => 1, 'clock' => '15:00', 'play_type' => 'Rush', 'play_text' => 'Rush for 2 yards',
        'home_score' => 99, 'away_score' => 0, 'possession_team_id' => $game->home_team_id,
        'down' => 1, 'distance' => 10, 'yards_to_endzone' => 75, 'yards_gained' => 2,
        'true_epa' => 77, 'source_revision' => hash('sha256', 'unaccepted-epa')]);
    $this->artisan('cfb:calculate-play-epa', ['--game_id' => $game->id, '--rebuild' => true])->assertSuccessful();
    expect((float) $unaccepted->fresh()->true_epa)->toBe(77.0)
        ->and($plays[0]->fresh()->is_epa_eligible)->toBeTrue();
});

it('batches EPA writes and cannot insert missing plays or cross a source revision', function () {
    [$game, $plays] = cfbLedgerFixture();
    $updates = [];
    foreach ($plays as $play) {
        $updates[] = ['id' => $play->id, 'is_epa_eligible' => true, 'expected_points_before' => 1.25,
            'expected_points_after' => 2.5, 'true_epa' => 1.25, 'epa_calculated_at' => now()];
    }
    $updates[] = [...$updates[0], 'id' => 999999];
    $before = Play::count();
    $writer = app(CfbPlayEpaWriter::class);
    $writer->write($game->id, hash('sha256', 'wrong-revision'), $updates);
    expect($plays[0]->fresh()->true_epa)->toBeNull();
    $writer->write($game->id, $plays[0]->source_revision, $updates);
    expect((float) $plays[0]->fresh()->true_epa)->toBe(1.25)->and(Play::count())->toBe($before)
        ->and($plays[0]->fresh()->home_score)->toBe(0);
});

it('holds normalization if the accepted play revision changes during EPA calculation', function () {
    [$game, $plays] = cfbLedgerFixture();
    $this->mock(TrueEpaCalculator::class)->shouldReceive('calculateForGame')->once()->andReturnUsing(function ($selected) use ($game) {
        GameDataCheck::create(['game_id' => $game->id, 'component' => 'plays', 'source_hash' => hash('sha256', 'new-revision'),
            'validator_version' => '2', 'state' => 'complete', 'discrepancies' => [], 'evidence' => [],
            'source_path' => 'new.json', 'accepted_at' => now()]);

        return $selected->mapWithKeys(fn ($play) => [$play->id => ['eligible' => false, 'ep_before' => null, 'ep_after' => null, 'epa' => null]])->all();
    });
    $this->artisan('cfb:calculate-play-epa', ['--game_id' => $game->id, '--rebuild' => true])->assertExitCode(2);
});
