<?php

use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;
use App\Models\ProviderSourceFile;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbOfficialBoxscoreRepair;
use App\Services\CFB\Data\CfbOfficialPlayTailRepair;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\CFB\PlayEpaDataService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Fixtures/cfb_complete_box.php';

function cfbOfficialBoxFixture(): array
{
    Storage::fake('local');
    config()->set('cfb.data.source_disk', 'local');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create(['school' => 'Eastern Michigan'])->id,
        'away_team_id' => Team::factory()->create(['school' => 'Sacramento State'])->id,
        'season' => 2026, 'game_date' => '2026-08-29', 'game_time' => '23:00:00',
        'status' => 'STATUS_FINAL', 'home_score' => 28, 'away_score' => 17]);
    $payload = cfbCompleteBox($game);
    $payload['boxscore']['teams'][1]['statistics'][0]['displayValue'] = '510';
    $payload['header'] = ['id' => $game->espn_event_id, 'competitions' => [['status' => ['type' => ['name' => 'STATUS_FINAL']],
        'competitors' => [['homeAway' => 'home', 'team' => ['id' => $game->homeTeam->espn_id], 'score' => '28'],
            ['homeAway' => 'away', 'team' => ['id' => $game->awayTeam->espn_id], 'score' => '17']]]]];
    $primary = app(CfbSourceRevision::class)->record($game, 'boxscore', $payload, app(CfbGameDataValidator::class)->boxscore($payload, $game));
    app(CfbSourceRevision::class)->record($game, 'plays', ['fixture' => true], ['state' => 'complete', 'discrepancies' => [], 'evidence' => []])
        ->update(['accepted_at' => now()]);
    $body = 'Reviewed school gamebook fixture';
    $spec = ['season' => 2026, 'date' => '2026-08-29', 'home' => 'Eastern Michigan', 'away' => 'Sacramento State',
        'home_score' => 28, 'away_score' => 17, 'url' => 'https://static.emueagles.com/reviewed-fixture.html',
        'sha256' => hash('sha256', $body), 'content_type' => 'text/html', 'reference' => 'Reviewed team totals',
        'stats' => ['home' => ['totalYards' => 513, 'netPassingYards' => 300, 'rushingYards' => 213],
            'away' => ['totalYards' => 513, 'netPassingYards' => 300, 'rushingYards' => 213]]];
    config()->set('cfb_official_sources.boxscores.'.$game->espn_event_id, $spec);
    Http::fake([$spec['url'] => Http::response($body)]);

    return [$game, $primary, $spec, $payload];
}

it('imports verified school totals while preserving primary quarantine, provenance and row integrity', function () {
    [$game, $primary] = cfbOfficialBoxFixture();
    $original = Storage::disk('local')->get($primary->source_path);
    $result = app(CfbOfficialBoxscoreRepair::class)->execute($game);
    expect($result['state'])->toBe('complete')
        ->and(TeamStat::where('game_id', $game->id)->where('team_id', $game->away_team_id)->value('total_yards'))->toBe(513)
        ->and(PlayerStat::where('game_id', $game->id)->sum('passing_yards'))->toBe(600)
        ->and(Storage::disk('local')->get($primary->source_path))->toBe($original)
        ->and($primary->fresh()->accepted_at)->toBeNull()
        ->and(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeTrue()
        ->and(ProviderSourceFile::where('sha256', $result['source_hash'])->value('provider'))->toBe('school_official_espn');
    $this->travel(1)->minutes();
    $primary->touch();
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeTrue();
    PlayerStat::where('game_id', $game->id)->first()->update(['passing_yards' => 299]);
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeFalse();
    app(CfbOfficialBoxscoreRepair::class)->execute($game);
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeTrue();
    $game->update(['home_score' => 29]);
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeFalse();
});

it('rejects changed school documents and totals that contradict player rows before import', function () {
    [$game, , $spec] = cfbOfficialBoxFixture();
    Http::swap(new Factory);
    Http::fake([$spec['url'] => Http::response('Changed unreviewed gamebook')]);
    expect(fn () => app(CfbOfficialBoxscoreRepair::class)->execute($game))->toThrow(RuntimeException::class, 'changed');
    Http::swap(new Factory);
    Http::fake([$spec['url'] => Http::response('Reviewed school gamebook fixture')]);
    $spec['stats']['away'] = ['totalYards' => 512, 'netPassingYards' => 299, 'rushingYards' => 213];
    config()->set('cfb_official_sources.boxscores.'.$game->espn_event_id, $spec);
    expect(fn () => app(CfbOfficialBoxscoreRepair::class)->execute($game))->toThrow(RuntimeException::class, 'player boxscores')
        ->and(PlayerStat::where('game_id', $game->id)->count())->toBe(0);
});

function cfbOfficialTailFixture(): array
{
    Storage::fake('local');
    config()->set('cfb.data.source_disk', 'local');
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create(['school' => 'California'])->id,
        'away_team_id' => Team::factory()->create(['school' => 'Clemson'])->id,
        'season' => 2026, 'game_date' => '2026-09-26', 'game_time' => '02:35:00',
        'status' => 'STATUS_FINAL', 'home_score' => 10, 'away_score' => 24]);
    $payload = ['count' => 1, 'items' => [['id' => 'anchor', 'sequenceNumber' => '10', 'period' => ['number' => 4],
        'clock' => ['displayValue' => '1:54'], 'type' => ['text' => 'Interception'], 'text' => 'Pass intercepted at CLEM40.',
        'homeScore' => 10, 'awayScore' => 24, 'start' => ['team' => ['id' => $game->homeTeam->espn_id]]]]];
    $primary = app(CfbSourceRevision::class)->record($game, 'plays', $payload, app(CfbGameDataValidator::class)->plays($payload, $game));
    app(CfbSourceRevision::class)->record($game, 'boxscore', ['fixture' => true], ['state' => 'complete', 'discrepancies' => [], 'evidence' => []])
        ->update(['accepted_at' => now()]);
    $body = 'Reviewed school closing possession';
    $spec = ['season' => 2026, 'date' => '2026-09-25', 'home' => 'California', 'away' => 'Clemson',
        'home_score' => 10, 'away_score' => 24, 'url' => 'https://calbears.com/reviewed-fixture.pdf',
        'sha256' => hash('sha256', $body), 'content_type' => 'application/pdf', 'reference' => 'Reviewed closing possession',
        'anchor' => ['id' => 'anchor', 'period' => 4, 'clock' => '1:54', 'type' => 'Interception'],
        'offense' => 'away', 'physical_plays' => 2, 'net_yards' => 5, 'rows' => [
            ['clock' => '1:51', 'type' => 'Rush', 'text' => 'Rush for 7 yards.', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 60, 'yards' => 7, 'end_down' => 2, 'end_distance' => 3, 'end_yards_to_endzone' => 53],
            ['clock' => '0:27', 'type' => 'Kneel Down', 'text' => 'Kneel down for loss of 2 yards.', 'down' => 2, 'distance' => 3, 'yards_to_endzone' => 53, 'yards' => -2, 'end_down' => 3, 'end_distance' => 5, 'end_yards_to_endzone' => 55],
            ['clock' => '0:00', 'type' => 'End of Game', 'text' => 'End of game, clock 00:00.'],
        ]];
    config()->set('cfb_official_sources.play_tails.'.$game->espn_event_id, $spec);
    Http::fake([$spec['url'] => Http::response($body)]);

    return [$game, $primary, $spec, $payload];
}

it('supplements a proven missing ending with school source IDs and detects raw row corruption', function () {
    [$game, $primary] = cfbOfficialTailFixture();
    $original = Storage::disk('local')->get($primary->source_path);
    $result = app(CfbOfficialPlayTailRepair::class)->execute($game);
    expect($result['state'])->toBe('complete')->and($result['supplemented_rows'])->toBe(3)
        ->and(Play::where('game_id', $game->id)->count())->toBe(4)
        ->and(Storage::disk('local')->get($primary->source_path))->toBe($original)
        ->and($primary->fresh()->accepted_at)->toBeNull()
        ->and(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeTrue();
    $kneel = Play::where('game_id', $game->id)->where('play_type', 'Kneel Down')->firstOrFail();
    expect($kneel->source_state['source_provider'])->toBe('school_official')
        ->and(app(PlayEpaDataService::class)->isEpaEligiblePlay($kneel))->toBeFalse();
    $kneel->update(['true_epa' => 1.5, 'epa_calculated_at' => now()]);
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeTrue();
    $kneel->update(['yards_gained' => -3]);
    expect(app(CfbDataReadiness::class)->forGame($game)['ready'])->toBeFalse();
});

it('rejects unproven pagination and inconsistent closing-drive observations', function () {
    [$game, , $spec, $payload] = cfbOfficialTailFixture();
    $bad = $spec;
    $bad['net_yards'] = 6;
    config()->set('cfb_official_sources.play_tails.'.$game->espn_event_id, $bad);
    expect(fn () => app(CfbOfficialPlayTailRepair::class)->execute($game))->toThrow(RuntimeException::class, 'yardage');
    config()->set('cfb_official_sources.play_tails.'.$game->espn_event_id, $spec);
    $payload['count'] = 2;
    app(CfbSourceRevision::class)->record($game, 'plays', $payload, app(CfbGameDataValidator::class)->plays($payload, $game));
    expect(fn () => app(CfbOfficialPlayTailRepair::class)->execute($game))->toThrow(RuntimeException::class, 'complete prefix')
        ->and(Play::where('game_id', $game->id)->count())->toBe(0);
});
