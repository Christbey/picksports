<?php

use App\Actions\NFL\CalculateElo;
use App\Exceptions\EloAdmissionRefused;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEloApplication;
use App\Models\SportEloEntityHead;
use App\Models\SportEloWriterGate;
use App\Services\Sports\Elo\EloAdmissionMode;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloInputAdmission;
use App\Services\Sports\Elo\EloSeasonRollover;
use App\Services\Sports\Elo\EloSourceContext;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriterProtocol;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Transitive Elo snapshot tests require explicitly selected disposable MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    config(['database.connections.elo_graph_peer' => config('database.connections.mysql'), 'elo_integrity.enabled' => true]);
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'nfl' && $protocol === 1;
        }
    });
    $this->graphDefinition = app(EloSportRegistry::class)->resolve('nfl', 'team');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting', 'calculation_version' => $this->graphDefinition['version'],
        'configuration_hash' => $this->graphDefinition['configuration_hash'], 'drain_reference' => 'synthetic-graph-drain-only', 'drain_verified_at' => now()->subMinute()]);
    $this->graphTeams = Team::factory()->count(4)->create(['elo_rating' => 1500])->all();
    $ids = array_map(fn ($team) => $team->id, $this->graphTeams);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('nfl', 2026, 'team', $ids);
    $admission->apply('nfl', 2026, 'team', $ids, $report['fingerprint'], 'synthetic-graph-default-review');
    config(['elo_integrity.write_target_fingerprints.nfl' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'active']);
    [$a, $b, $c, $d] = $this->graphTeams;
    $this->graphAncestor = graphSnapshotGame($b, $c, 8);
    (new CalculateElo)->execute($this->graphAncestor);
    (new CalculateElo)->execute(graphSnapshotGame($a, $b, 15));
    $this->graphTarget = graphSnapshotGame($a, $d, 22);
});

afterEach(function () {
    foreach ([DB::connection(), DB::connection('elo_graph_peer')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('elo_graph_peer');
});

function graphSnapshotGame(Team $home, Team $away, int $day, int $season = 2026): Game
{
    return Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'week' => $day, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => sprintf('%d-10-%02d', $season, $day), 'game_time' => '18:00:00', 'home_score' => 20, 'away_score' => 10]);
}

it('refuses an ancestor-only source correction committed behind an old repeatable-read snapshot', function () {
    DB::beginTransaction();
    Game::whereKey($this->graphAncestor->id)->firstOrFail();
    DB::connection('elo_graph_peer')->table('nfl_games')->where('id', $this->graphAncestor->id)->update(['away_score' => 40]);
    expect(fn () => (new CalculateElo)->execute($this->graphTarget))->toThrow(EloAdmissionRefused::class, 'transitive_dependency_source_correction');
    DB::rollBack();
    expect(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4)
        ->and(SportEloEntityHead::where('last_game_id', $this->graphTarget->id)->count())->toBe(0);
});

it('discovers a newly inserted ancestor-only prior final despite an older consistent snapshot', function () {
    [$a, $b, $c] = $this->graphTeams;
    DB::beginTransaction();
    Game::count();
    DB::connection('elo_graph_peer')->table('nfl_games')->insert(['espn_event_id' => 'synthetic-graph-new-prior',
        'home_team_id' => $b->id, 'away_team_id' => $c->id, 'season' => 2026, 'week' => 1, 'season_type' => 2,
        'status' => 'STATUS_FINAL', 'game_date' => '2026-10-01', 'game_time' => '18:00:00', 'home_score' => 0, 'away_score' => 0]);
    expect(fn () => (new CalculateElo)->execute($this->graphTarget))->toThrow(EloAdmissionRefused::class, 'dependency_unreceipted_ancestor_final');
    DB::rollBack();
    expect(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4);
});

it('locks the ancestor-only final range before entities so a new earlier final cannot slip into admission', function () {
    [$a, $b, $c, $d] = $this->graphTeams;
    DB::beginTransaction();
    app(EloWriterProtocol::class)->lockGate(DB::connection(), 'nfl', $this->graphDefinition);
    $sources = app(EloSourceContext::class);
    $sources->beginObservation();
    $sources->lockGames(DB::connection(), 'nfl', $this->graphDefinition, ['team' => [$a->id, $d->id]], [$this->graphTarget->id], 2026, [2026]);
    $peer = DB::connection('elo_graph_peer');
    $peer->statement('SET SESSION innodb_lock_wait_timeout=1');
    $row = ['espn_event_id' => 'synthetic-ancestor-range', 'home_team_id' => $b->id, 'away_team_id' => $c->id,
        'season' => 2026, 'week' => 1, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-01', 'game_time' => '12:00:00', 'home_score' => 0, 'away_score' => 0];
    try {
        $peer->table('nfl_games')->insert($row);
        $this->fail('An ancestor-only prior final escaped the source range lock.');
    } catch (QueryException $error) {
        expect((int) $error->errorInfo[1])->toBe(1205);
    } finally {
        DB::rollBack();
    }
    expect($peer->table('nfl_games')->insert($row))->toBeTrue()
        ->and(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4);
});

it('rechecks old-season opponent sources during rollover behind a stale game snapshot', function () {
    [$a, $b, $c, $d] = $this->graphTeams;
    // The prepared target is needed to finish the season before rollover; advance the other pair too.
    (new CalculateElo)->execute($this->graphTarget);
    (new CalculateElo)->execute(graphSnapshotGame($c, $d, 29));
    $this->travelTo(CarbonImmutable::parse('2027-11-26 12:00:00 UTC'));
    app(EloSeasonRollover::class)->execute('nfl', 2027, ['team' => '0.3']);
    (new CalculateElo)->execute(graphSnapshotGame($a, $b, 1, 2027));
    (new CalculateElo)->execute(graphSnapshotGame($c, $d, 8, 2027));
    DB::beginTransaction();
    Game::whereKey($this->graphAncestor->id)->firstOrFail();
    DB::connection('elo_graph_peer')->table('nfl_games')->where('id', $this->graphAncestor->id)->update(['away_score' => 40]);
    expect(fn () => app(EloSeasonRollover::class)->execute('nfl', 2028, ['team' => '0.3']))->toThrow(EloAdmissionRefused::class, 'transitive_dependency_source_correction');
    DB::rollBack();
    expect(SportEloApplication::count())->toBe(6)->and(EloRating::count())->toBe(16)
        ->and(SportEloEntityHead::where('season', 2028)->count())->toBe(0);
});

function graphSnapshotForecast($test): Game
{
    $test->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    (new CalculateElo)->execute($test->graphTarget);
    [$a, , , $d] = $test->graphTeams;

    return Game::factory()->create(['home_team_id' => $a->id, 'away_team_id' => $d->id,
        'season' => 2026, 'week' => 4, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-11-27', 'game_time' => '18:00:00', 'home_score' => null, 'away_score' => null]);
}

it('refuses corrected ancestor input during read admission behind a consistent snapshot', function () {
    $forecast = graphSnapshotForecast($this);
    expect($forecast->getRawOriginal('game_date'))->toBe('2026-11-27 00:00:00')
        ->and(DB::table('nfl_games')->where('id', $forecast->id)->value('game_date'))->toBe('2026-11-27');
    DB::beginTransaction();
    Game::count();
    DB::connection('elo_graph_peer')->table('nfl_games')->where('id', $this->graphAncestor->id)->update(['away_score' => 40]);
    expect(fn () => app(EloInputAdmission::class)->teams('nfl', $forecast))
        ->toThrow(EloAdmissionRefused::class, 'transitive_dependency_source_correction');
    DB::rollBack();
    expect(EventInputSnapshot::count())->toBe(0)->and(CanonicalPrediction::count())->toBe(0);
});

it('refuses current target identity drift during read admission behind a consistent snapshot', function () {
    $forecast = graphSnapshotForecast($this);
    DB::beginTransaction();
    Game::whereKey($forecast->id)->firstOrFail();
    DB::connection('elo_graph_peer')->table('nfl_games')->where('id', $forecast->id)->update(['home_team_id' => $this->graphTeams[2]->id]);
    expect(fn () => app(EloInputAdmission::class)->teams('nfl', $forecast))
        ->toThrow(EloAdmissionRefused::class, 'input_target_changed_home_team_id');
    DB::rollBack();
    expect(EventInputSnapshot::count())->toBe(0)->and(CanonicalPrediction::count())->toBe(0);
});

it('uses current durable gate evidence when configuration is off behind an older gate snapshot', function () {
    config(['elo_integrity.enabled' => false]);
    DB::beginTransaction();
    DB::table('sport_elo_writer_gates')->where('sport', 'nba')->first();
    DB::connection('elo_graph_peer')->table('sport_elo_writer_gates')->where('sport', 'nba')->update(['mode' => 'admitting']);
    expect(fn () => app(EloAdmissionMode::class)->managed('nba', DB::connection()))
        ->toThrow(EloAdmissionRefused::class, 'durable_elo_admission_disabled');
    DB::rollBack();
});

function graphImportedClockPayload(string $id, ?string $commence): array
{
    return ['event_id' => 'nflverse:'.$id, 'commence_time' => $commence,
        'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']];
}

it('uses current missing-clock metadata rather than an older observed-midnight snapshot', function () {
    $id = 'synthetic-current-clock';
    $this->graphTarget->update(['nflverse_game_id' => $id, 'game_time' => '00:00:00',
        'odds_data' => graphImportedClockPayload($id, '2026-10-22T00:00:00Z')]);
    DB::beginTransaction();
    DB::table('nfl_games')->where('id', $this->graphTarget->id)->value('odds_data');
    DB::connection('elo_graph_peer')->table('nfl_games')->where('id', $this->graphTarget->id)
        ->update(['odds_data' => json_encode(graphImportedClockPayload($id, null), JSON_THROW_ON_ERROR)]);
    expect(fn () => (new CalculateElo)->execute($this->graphTarget))
        ->toThrow(EloAdmissionRefused::class, 'proven_missing_imported_clock');
    DB::rollBack();
    expect(SportEloApplication::count())->toBe(2)->and(EloRating::count())->toBe(4);
});

it('refuses current oversized clock metadata before body transfer behind an old snapshot', function () {
    $id = 'synthetic-current-clock-budget';
    $this->graphTarget->update(['nflverse_game_id' => $id, 'game_time' => '00:00:00',
        'odds_data' => graphImportedClockPayload($id, '2026-10-22T00:00:00Z')]);
    DB::beginTransaction();
    DB::table('nfl_games')->where('id', $this->graphTarget->id)->value('odds_data');
    DB::connection('elo_graph_peer')->table('nfl_games')->where('id', $this->graphTarget->id)
        ->update(['odds_data' => json_encode([...graphImportedClockPayload($id, null), 'padding' => str_repeat('x', 20000)], JSON_THROW_ON_ERROR)]);
    $bodyReads = 0;
    DB::listen(function ($query) use (&$bodyReads) {
        $sql = strtolower(str_replace(['`', '"'], '', $query->sql));
        if (str_contains($sql, 'select odds_data from nfl_games') || str_contains($sql, 'select * from nfl_games')) {
            $bodyReads++;
        }
    });
    expect(fn () => (new CalculateElo)->execute($this->graphTarget))
        ->toThrow(EloAdmissionRefused::class, 'import_clock_metadata_budget');
    DB::rollBack();
    expect($bodyReads)->toBe(0)->and(SportEloApplication::count())->toBe(2);
});

it('distinguishes metadata-proven fractional midnight placeholders from observed MySQL TIME6 midnight', function () {
    DB::statement('ALTER TABLE nfl_games MODIFY game_time TIME(6) NULL');
    // Existing exact receipts intentionally retain their old raw clock representation.
    // The positive branch uses newly admitted disjoint entities after the schema variation.
    $teams = Team::factory()->count(2)->create(['elo_rating' => 1500]);
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting']);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('nfl', 2026, 'team', $teams->modelKeys());
    $admission->apply('nfl', 2026, 'team', $teams->modelKeys(), $report['fingerprint'], 'synthetic-time6-disjoint-baseline');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'active']);
    $this->graphTarget = graphSnapshotGame($teams[0], $teams[1], 22);
    $id = 'synthetic-time6-clock';
    $this->graphTarget->update(['nflverse_game_id' => $id, 'game_time' => '00:00:00.000000',
        'odds_data' => graphImportedClockPayload($id, null)]);
    expect(DB::table('nfl_games')->where('id', $this->graphTarget->id)->value('game_time'))->toBe('00:00:00.000000');
    expect(fn () => (new CalculateElo)->execute($this->graphTarget))
        ->toThrow(EloAdmissionRefused::class, 'proven_missing_imported_clock');
    expect(SportEloApplication::count())->toBe(2);
    $this->graphTarget->update(['odds_data' => graphImportedClockPayload($id, '2026-10-22T00:00:00Z')]);
    (new CalculateElo)->execute($this->graphTarget->fresh());
    expect(SportEloApplication::count())->toBe(3)->and(EloRating::count())->toBe(6);
});
