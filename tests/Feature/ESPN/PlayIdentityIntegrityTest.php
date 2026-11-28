<?php

use App\Actions\ESPN\NBA\SyncPlays;
use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\SourceAssessmentRevision;
use App\Models\NBA\Game;
use App\Models\ProviderSourceFile;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\ESPN\NBA\EspnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function playIdentityFixture(string $sport): array
{
    $namespace = strtoupper($sport);
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $playClass = "App\\Models\\{$namespace}\\Play";
    $home = $teamClass::factory()->create();
    $away = $teamClass::factory()->create();
    $game = $gameClass::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_IN_PROGRESS']);
    $old = $playClass::create(['game_id' => $game->id, 'espn_play_id' => 'stored-original', 'sequence_number' => 1,
        'play_text' => 'Stored original', 'home_score' => 5, 'away_score' => 0,
        ...($sport === 'mlb' ? ['inning' => 1, 'inning_half' => 'top'] : ['period' => 1, 'clock' => '10:00'])]);
    if ($sport === 'cfb') {
        Storage::fake('local');
        config(['cfb.data.source_disk' => 'local']);
    }

    return [$game, $old, $home, $away, $teamClass, $playClass];
}

function playIdentityRaw(string $sport, ?string $team, ?string $opponent = null): array
{
    $raw = ['id' => 'incoming-new', 'period' => ['number' => 1], 'clock' => ['displayValue' => '10:00'], 'text' => 'Synthetic play', 'homeScore' => 0, 'awayScore' => 0];
    if ($team !== null) {
        if ($sport === 'cfb') {
            $raw['start'] = ['team' => ['id' => $team], 'down' => 1, 'distance' => 10, 'yardsToEndzone' => 75];
        } else {
            $raw['team'] = ['id' => $team];
        }
    }
    if ($sport === 'mlb' && $opponent !== null) {
        $raw['pitchingTeam'] = ['id' => $opponent];
    }

    return $raw;
}

function playIdentityAction(string $sport, object $game, array $raw, ?Closure $duringFetch = null): object
{
    $namespace = strtoupper($sport);
    $serviceClass = "App\\Services\\ESPN\\{$namespace}\\EspnService";
    $actionClass = "App\\Actions\\ESPN\\{$namespace}\\SyncPlays";
    $service = Mockery::mock($serviceClass);
    if ($sport === 'cbb') {
        $service->shouldReceive('getGame')->once()->andReturn(['competitions' => [['id' => $game->espn_event_id]]]);
    }
    $service->shouldReceive(in_array($sport, ['cfb', 'cbb'], true) ? 'getPlays' : 'getGame')->once()->andReturnUsing(function () use ($duringFetch, $raw, $sport) {
        $duringFetch?->__invoke();

        return in_array($sport, ['cfb', 'cbb'], true) ? ['count' => 1, 'items' => [$raw]] : ['plays' => [$raw]];
    });

    return new $actionClass($service);
}

it('refuses provided play teams outside the captured matchup without replacing prior rows', function (string $sport, string $case) {
    [$game, $old, $home, $away, $teamClass, $playClass] = playIdentityFixture($sport);
    $provided = $case === 'third team' ? $teamClass::factory()->create()->espn_id : 'unresolvable-provider-team';
    $raw = playIdentityRaw($sport, (string) $provided, (string) $away->espn_id);
    $before = $old->fresh()->getRawOriginal();
    $action = playIdentityAction($sport, $game, $raw);
    if ($sport === 'cfb') {
        expect($action->execute($game->espn_event_id))->toBe(0);
        expect(GameDataCheck::where('game_id', $game->id)->sole()->discrepancies)->toContain('invalid_play_team_reference');
    } else {
        expect(fn () => $action->execute($game->espn_event_id))->toThrow(RuntimeException::class);
    }
    expect($old->fresh()->getRawOriginal())->toBe($before)->and($playClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['nba', 'mlb', 'cfb'])->with(['third team', 'unresolved']);

it('binds valid opponent references and leaves absent team references unknown', function (string $sport, bool $provided) {
    [$game, $old, $home, $away, , $playClass] = playIdentityFixture($sport);
    $action = playIdentityAction($sport, $game, playIdentityRaw($sport, $provided ? (string) $away->espn_id : null, $provided ? (string) $home->espn_id : null));
    expect($action->execute($game->espn_event_id))->toBe(1);
    $row = $playClass::where('game_id', $game->id)->sole();
    expect($row->espn_play_id)->toBe('incoming-new')
        ->and($row->{$sport === 'mlb' ? 'batting_team_id' : 'possession_team_id'})->toBe($provided ? $away->id : null);
    if ($sport === 'mlb') {
        expect($row->pitching_team_id)->toBe($provided ? $home->id : null);
    }
})->with(['nba', 'mlb', 'cfb'])->with([true, false]);

it('refuses a changed game identity after provider fetch and preserves stored plays', function (string $change) {
    [$game, $old, $home, $away, $teamClass, $playClass] = playIdentityFixture('nba');
    $replacement = $teamClass::factory()->create();
    $before = $old->fresh()->getRawOriginal();
    $action = playIdentityAction('nba', $game, playIdentityRaw('nba', (string) $home->espn_id), function () use ($game, $change, $replacement) {
        $game->newQuery()->whereKey($game->id)->update([$change => $change === 'home_team_id' ? $replacement->id : 'different-event-after-fetch']);
    });
    expect(fn () => $action->execute($game->espn_event_id))->toThrow(RuntimeException::class);
    expect($old->fresh()->getRawOriginal())->toBe($before)->and($playClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['home_team_id', 'espn_event_id']);

it('checks raw CBB actor references even when the event is not an attributable possession', function () {
    [$game, $old, , , $teamClass, $playClass] = playIdentityFixture('cbb');
    $foreign = $teamClass::factory()->create();
    $raw = [...playIdentityRaw('cbb', (string) $foreign->espn_id), 'type' => ['text' => 'Timeout']];
    $before = $old->fresh()->getRawOriginal();
    $action = playIdentityAction('cbb', $game, $raw);
    expect(fn () => $action->execute($game->espn_event_id))->toThrow(RuntimeException::class);
    expect($old->fresh()->getRawOriginal())->toBe($before)->and($playClass::where('game_id', $game->id)->count())->toBe(1);
});

it('refuses changed owned provider team bindings after fetch before play writes', function () {
    [$game, $old, $home, , , $playClass] = playIdentityFixture('nba');
    $before = $old->fresh()->getRawOriginal();
    $action = playIdentityAction('nba', $game, playIdentityRaw('nba', (string) $home->espn_id), function () use ($home) {
        $home->newQuery()->whereKey($home->id)->update(['espn_id' => 'changed-provider-binding']);
    });
    expect(fn () => $action->execute($game->espn_event_id))->toThrow(RuntimeException::class);
    expect($old->fresh()->getRawOriginal())->toBe($before)->and($playClass::where('game_id', $game->id)->count())->toBe(1);
});

function legacyCfbScorePolicyFixture(bool $missing): array
{
    [$game, $old, $home] = playIdentityFixture('cfb');
    $raw = playIdentityRaw('cfb', (string) $home->espn_id);
    if ($missing) {
        unset($raw['homeScore']);
    }
    $body = ['count' => 1, 'items' => [$raw]];
    $validation = app(CfbGameDataValidator::class)->plays($body, $game);
    unset($validation['evidence']['score_observation_policy']);
    $check = app(CfbSourceRevision::class)->record($game, 'plays', $body, $validation);
    $attrs = FootballPlayData::fromEspnResponse($raw, 0)->toArray();
    $old->forceFill([...$attrs, 'home_score' => 0, 'possession_team_id' => $home->id, 'source_revision' => $check->source_hash,
        'source_state' => $raw, 'true_epa' => 1.25, 'expected_points_before' => 1, 'expected_points_after' => 2, 'epa_calculated_at' => now()])->save();
    $check->update(['accepted_at' => now()]);

    return [$game, $old->fresh(), $raw, $check->fresh()];
}

it('preserves same-raw observed-zero CFB EPA when a new processing-policy assessment is admitted', function () {
    [$game, $old, $raw, $check] = legacyCfbScorePolicyFixture(false);
    $before = $old->getRawOriginal();
    $path = $check->source_path;
    $bytes = Storage::disk('local')->get($path);
    $source = ProviderSourceFile::where('sha256', $check->source_hash)->sole();
    $sourceBefore = $source->getRawOriginal();
    expect(playIdentityAction('cfb', $game, $raw)->execute($game->espn_event_id))->toBe(0);
    expect($old->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('local')->get($path))->toBe($bytes)
        ->and($source->fresh()->getRawOriginal())->toBe($sourceBefore)
        ->and($check->fresh()->evidence['score_observation_policy'])->toBe('nullable-whole-score-v1');
});

it('refreshes a legacy fabricated-zero CFB projection to missing without rewriting its archived source or old receipts', function () {
    [$game, $old, $raw, $check] = legacyCfbScorePolicyFixture(true);
    $beforeRevisions = SourceAssessmentRevision::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
    $path = $check->source_path;
    $bytes = Storage::disk('local')->get($path);
    expect(playIdentityAction('cfb', $game, $raw)->execute($game->espn_event_id))->toBe(1);
    expect($old->fresh()->home_score)->toBeNull()->and($old->fresh()->true_epa)->toBeNull()->and($old->fresh()->epa_calculated_at)->toBeNull()
        ->and(Storage::disk('local')->get($path))->toBe($bytes);
    foreach ($beforeRevisions as $revision) {
        expect(SourceAssessmentRevision::findOrFail($revision['id'])->getRawOriginal())->toBe($revision);
    }
});

class PlayIntegrityAuxGame extends Game
{
    protected $connection = 'play_integrity_aux';
}

it('keeps play and owned-team reads and writes on the resolved named game connection', function () {
    config(['database.connections.play_integrity_aux' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    $aux = DB::connection('play_integrity_aux');
    try {
        [$game, $old, $home, $away] = playIdentityFixture('nba');
        foreach (['nba_teams', 'nba_games', 'nba_plays'] as $table) {
            $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
            $aux->statement($sql);
        }
        $aux->table('nba_teams')->insert([
            [...$home->getRawOriginal(), 'espn_id' => '812001'], [...$away->getRawOriginal(), 'espn_id' => '812002'],
        ]);
        $aux->table('nba_games')->insert([...$game->getRawOriginal(), 'espn_event_id' => 'aux-fixture-event', 'sport_event_id' => null]);
        $aux->table('nba_plays')->insert([...$old->getRawOriginal(), 'espn_play_id' => 'stored-auxiliary']);
        $before = $old->fresh()->getRawOriginal();
        $service = Mockery::mock(EspnService::class);
        $service->shouldReceive('getGame')->once()->with('aux-fixture-event')->andReturn(['plays' => [playIdentityRaw('nba', '812001')]]);
        $action = new class($service) extends SyncPlays
        {
            protected const GAME_MODEL_CLASS = PlayIntegrityAuxGame::class;
        };
        $connections = [];
        $watching = true;
        DB::listen(function ($query) use (&$connections, &$watching) {
            if ($watching && preg_match('/nba_(?:teams|plays)/', $query->sql)) {
                $connections[] = $query->connection->getName();
            }
        });
        expect($action->execute('aux-fixture-event'))->toBe(1);
        $watching = false;
        expect($connections)->not->toBeEmpty()->and(array_unique($connections))->toBe(['play_integrity_aux'])
            ->and($old->fresh()->getRawOriginal())->toBe($before)
            ->and($aux->table('nba_plays')->sole()->espn_play_id)->toBe('incoming-new')
            ->and($aux->table('nba_plays')->sole()->possession_team_id)->toBe($home->id);
    } finally {
        DB::purge('play_integrity_aux');
    }
})->skip(fn () => config('database.default') !== 'sqlite', 'Explicit independent in-memory SQLite target; native race acceptance is separate.');

it('refuses duplicate provider play identities before replacing the stored batch', function (string $sport) {
    [$game, $old, $home, , , $playClass] = playIdentityFixture($sport);
    $before = $old->fresh()->getRawOriginal();
    $serviceClass = 'App\\Services\\ESPN\\'.strtoupper($sport).'\\EspnService';
    $actionClass = 'App\\Actions\\ESPN\\'.strtoupper($sport).'\\SyncPlays';
    $raw = playIdentityRaw($sport, (string) $home->espn_id);
    $service = Mockery::mock($serviceClass);
    $service->shouldReceive('getGame')->once()->andReturn(['plays' => [$raw, $raw]]);
    expect(fn () => (new $actionClass($service))->execute($game->espn_event_id))->toThrow(RuntimeException::class);
    expect($old->fresh()->getRawOriginal())->toBe($before)->and($playClass::where('game_id', $game->id)->count())->toBe(1);
})->with(['nba', 'mlb']);

it('refuses malformed provided play IDs before replacing stored plays', function (mixed $id) {
    [$game, $old, $home] = playIdentityFixture('nba');
    $before = $old->fresh()->getRawOriginal();
    $raw = [...playIdentityRaw('nba', (string) $home->espn_id), 'id' => $id];
    $action = playIdentityAction('nba', $game, $raw);
    expect(fn () => $action->execute($game->espn_event_id))->toThrow(InvalidArgumentException::class);
    expect($old->fresh()->getRawOriginal())->toBe($before);
})->with([
    'array identity' => [['key' => 'value']], 'boolean' => true, 'fractional numeric identity' => 1.5, 'numeric overflow to float' => 1.0e30,
    'control character' => "opaque\nidentity", 'schema overflow' => str_repeat('x', 51),
]);

it('preserves bounded opaque play identities without case normalization or numeric narrowing', function () {
    [$game, , $home, , , $playClass] = playIdentityFixture('nba');
    $ids = ['opaque:v1-A_β-0001', 'opaque:v1-a_β-0001', PHP_INT_MAX, '001'];
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->andReturn(['plays' => array_map(fn ($id) => [...playIdentityRaw('nba', (string) $home->espn_id), 'id' => $id], $ids)]);
    expect((new SyncPlays($service))->execute($game->espn_event_id))->toBe(4)
        ->and($playClass::where('game_id', $game->id)->orderBy('sequence_number')->pluck('espn_play_id')->all())->toBe(array_map(strval(...), $ids));
});

it('keeps distinct opaque CFB IDs separate and removes only rows absent by exact identity', function () {
    [$game, , $home, , , $playClass] = playIdentityFixture('cfb');
    $first = [...playIdentityRaw('cfb', (string) $home->espn_id), 'id' => 'Case-A'];
    $second = [...$first, 'id' => 'case-a'];
    $service = Mockery::mock(App\Services\ESPN\CFB\EspnService::class);
    $service->shouldReceive('getPlays')->once()->andReturn(['count' => 2, 'items' => [$first, $second]]);
    expect((new App\Actions\ESPN\CFB\SyncPlays($service))->execute($game->espn_event_id))->toBe(2)
        ->and($playClass::where('game_id', $game->id)->orderBy('sequence_number')->pluck('espn_play_id')->all())->toBe(['Case-A', 'case-a']);
    $again = playIdentityAction('cfb', $game, $second);
    expect($again->execute($game->espn_event_id))->toBe(1)
        ->and($playClass::where('game_id', $game->id)->sole()->espn_play_id)->toBe('case-a');
});
