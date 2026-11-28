<?php

use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\CFB\Live\LivePropProjector;
use App\Services\CFB\Live\LiveSnapshotRecorder;
use App\Services\ESPN\CFB\EspnService;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\CFB\CfbGameStart;
use App\Support\Sports\CanonicalEventStart;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
});

it('expands native TIME storage with nullable JSON without certifying or rewriting legacy rows', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'native-legacy', 'game_date' => '2027-01-13', 'game_time' => '00:00:00', 'home_score' => 0, 'away_score' => 0]);
    DB::statement('ALTER TABLE cfb_games DROP COLUMN kickoff_observation');
    $before = (array) DB::table('cfb_games')->where('id', $game->id)->first();
    $columns = array_map(fn ($row) => (array) $row, DB::select('SHOW FULL COLUMNS FROM cfb_games'));
    $migration = require database_path('migrations/2026_11_27_210000_add_cfb_kickoff_observation.php');
    $migration->up();
    $after = (array) DB::table('cfb_games')->where('id', $game->id)->first();
    expect($after['kickoff_observation'])->toBeNull();
    unset($after['kickoff_observation']);
    expect($after)->toBe($before);
    $afterColumns = array_map(fn ($row) => (array) $row, DB::select('SHOW FULL COLUMNS FROM cfb_games'));
    expect(array_values(array_filter($afterColumns, fn ($row) => $row['Field'] !== 'kickoff_observation')))->toBe($columns);
    $clock = DB::selectOne('SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['cfb_games', 'game_time']);
    $column = DB::selectOne('SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['cfb_games', 'kickoff_observation']);
    expect($clock->DATA_TYPE)->toBe('time')->and($clock->IS_NULLABLE)->toBe('NO')
        ->and($column->DATA_TYPE)->toBe('json')->and($column->IS_NULLABLE)->toBe('YES')
        ->and(CfbGameStart::for($game->fresh()))->toBeNull()->and(CfbGameStart::status($game->fresh()))->toBe('legacy_clock_unverified');
});

it('retains native date inventory while TBD invalid flags and date-only schedules cannot claim a kickoff', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $events = [];
    foreach (['time_invalid', 'tbd', 'date_only', 'midnight'] as $kind) {
        $date = $kind === 'date_only' ? '2027-01-13' : ($kind === 'midnight' ? '2027-01-13T00:00:00Z' : '2027-01-13T04:00:00Z');
        $competition = ['date' => $date, 'timeValid' => $kind !== 'time_invalid', 'dateValid' => true,
            'status' => ['type' => ['name' => 'STATUS_SCHEDULED', 'detail' => $kind === 'tbd' ? '1/12 - TBD' : 'Scheduled']],
            'competitors' => [['homeAway' => 'home', 'team' => ['id' => $home->espn_id]], ['homeAway' => 'away', 'team' => ['id' => $away->espn_id]]]];
        $events[] = ['id' => 'native-'.$kind, 'date' => $date, 'season' => ['year' => 2026, 'type' => 3], 'week' => ['number' => 1],
            'status' => ['type' => ['name' => 'STATUS_SCHEDULED']], 'competitions' => [$competition]];
    }
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getSchedule')->once()->andReturn(['events' => $events]);
    expect((new SyncGamesFromSchedule($service))->execute((string) $home->espn_id, 2026))->toBe(4);
    foreach (Game::orderBy('id')->get() as $game) {
        expect($game->game_date->toDateString())->toBe('2027-01-13')
            ->and(CfbGameStart::for($game)?->toIso8601String())->toBe($game->espn_event_id === 'native-midnight' ? '2027-01-13T00:00:00+00:00' : null);
    }
    $calendar = CfbPropEligibility::class;
    expect($calendar::onDate(Game::query(), '2027-01-13')->count())->toBe(3)
        ->and($calendar::onDate(Game::query(), '2027-01-12')->count())->toBe(1);
    Http::assertNothingSent();
});

it('atomically downgrades and restores current native summary clocks without stale-event fallback or losing carriers', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'espn_event_id' => 'native-summary',
        'season' => 2026, 'status' => 'STATUS_SCHEDULED', 'venue_name' => 'Preserved venue',
        ...CfbGameStart::attributes('2027-01-13T00:00:00Z', [], 'espn_schedule', 'native-summary')]);
    app(SportEventIdentitySynchronizer::class)->sync('cfb', $game);
    $cached = $game->fresh()->sportEvent;
    $payload = ['header' => ['id' => 'native-summary', 'season' => ['year' => 2026], 'competitions' => [[
        'date' => '2027-01-13T00:00:00Z', 'timeValid' => true, 'dateValid' => true, 'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        'competitors' => [['homeAway' => 'home', 'team' => ['id' => $home->espn_id]], ['homeAway' => 'away', 'team' => ['id' => $away->espn_id]]],
    ]]]];
    $unknown = $payload;
    data_set($unknown, 'header.competitions.0.timeValid', false);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->twice()->andReturn($unknown, $payload);
    $sync = new SyncGameMetadata($service);
    expect(fn () => $sync->execute($game))->toThrow(RuntimeException::class);
    $current = $game->fresh();
    expect(CfbGameStart::for($current))->toBeNull()->and($current->game_time)->toBe('00:00:00')
        ->and($current->venue_name)->toBe('Preserved venue')->and($current->sportEvent->starts_at)->toBeNull()
        ->and(CanonicalEventStart::forCurrentCfbEvent($cached))->toBeNull();
    expect($sync->execute($current))->toBeTrue()
        ->and(CfbGameStart::for($game->fresh())?->toIso8601String())->toBe('2027-01-13T00:00:00+00:00');
    DB::table('cfb_games')->where('id', $game->id)->update(['game_time' => '01:00:00']);
    expect(CfbGameStart::for($game->fresh()))->toBeNull()->and(CanonicalEventStart::forCurrentCfbEvent($cached))->toBeNull();
    Http::assertNothingSent();
});

it('keeps native raw day inventory consistent when JSON known status has stale clock or event bindings', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach (['clock', 'event'] as $kind) {
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'espn_event_id' => 'native-edge-'.$kind,
            ...CfbGameStart::attributes('2027-01-13T02:00:00Z', [], 'espn_schedule', 'native-edge-'.$kind)]);
        $game->forceFill($kind === 'clock' ? ['game_time' => '03:00:00'] : ['espn_event_id' => 'native-rebound-event'])->saveQuietly();
        expect(CfbGameStart::for($game->fresh()))->toBeNull();
    }
    $calendar = CfbPropEligibility::class;
    expect($calendar::onDate(Game::query(), '2027-01-13')->count())->toBe(2)
        ->and($calendar::onDate(Game::query(), '2027-01-12')->count())->toBe(0);
});

it('recovers an unavailable native live baseline and freezes only the current exact kickoff identity', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-12T18:00:00Z'));
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'native-baseline', 'status' => 'STATUS_IN_PROGRESS', 'game_date' => '2026-09-12', 'game_time' => '17:00:00',
        'kickoff_observation' => CfbGameStart::observation('2026-09-12T17:00:00Z', ['timeValid' => false], 'espn_schedule', 'native-baseline')]);
    $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 10, 'predicted_total' => 50, 'win_probability' => .7,
        'confidence_score' => 65, 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
    $prediction->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->saveQuietly();
    $recorder = app(LiveSnapshotRecorder::class);
    $unknown = $recorder->baseline($game);
    expect($unknown['source'])->toBe('unavailable');
    $first = $recorder->record($game, $unknown, null, 'missing_pregame')->fresh()->getRawOriginal();
    $game->fill(CfbGameStart::attributes('2026-09-12T17:00:00Z', [], 'espn_scoreboard', 'native-baseline'))->saveQuietly();
    $known = $recorder->baseline($game->fresh());
    expect($known['source'])->toBe('legacy_pregame')->and((float) $known['total'])->toBe(50.0);
    $recorder->record($game, $known, null, 'live');
    $cached = $recorder->baseline($game->fresh());
    expect($cached['source'])->toBe('legacy_pregame')->and($cached['captured_at'])->toBe($known['captured_at'])
        ->and(data_get($cached, 'kickoff_identity.starts_at_utc'))->toBe('2026-09-12T17:00:00+00:00')
        ->and(LivePredictionSnapshot::first()->getRawOriginal())->toBe($first);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => Carbon\Carbon::parse('2026-09-12T17:00:00Z')->setTimezone(config('app.timezone'))]);
    $game->update(['sport_event_id' => $event->id]);
    expect($recorder->baseline($game->fresh())['source'])->toBe('unavailable');
    $canonical = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id, 'phase' => 'pregame',
        'publication_state' => 'published', 'generated_at' => now()->subHours(2), 'published_at' => now()->subHours(2), 'created_at' => now()->subHours(2),
        'output_metadata' => ['input_quality' => ['qualified' => true]], 'model_version' => '1.6.2']);
    $canonical->markets()->create(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => -20]);
    $canonical->markets()->create(['market_type' => 'total', 'selection' => 'combined', 'projected_line' => 60]);
    $canonical->markets()->create(['market_type' => 'moneyline', 'selection' => 'home', 'probability' => .96]);
    $linked = $recorder->baseline($game->fresh());
    expect($linked['source'])->toBe('canonical_pregame')->and($linked['canonical_prediction_id'])->toBe($canonical->id);
    $recorder->record($game, $linked, null, 'live');
    expect($recorder->baseline($game->fresh())['canonical_prediction_id'])->toBe($canonical->id);
    $next = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => $event->starts_at]);
    $game->update(['sport_event_id' => $next->id]);
    expect($recorder->baseline($game->fresh())['source'])->toBe('unavailable');
    $game->update(['sport_event_id' => null]);
    expect($recorder->baseline($game->fresh())['source'])->toBe('legacy_pregame');
    $game->fill(CfbGameStart::attributes('2026-09-12T15:00:00Z', [], 'espn_scoreboard', 'native-baseline'))->saveQuietly();
    expect($recorder->baseline($game->fresh())['source'])->toBe('unavailable')
        ->and(LivePredictionSnapshot::first()->getRawOriginal())->toBe($first);
});

it('retains native final observed zero statistics with unknown kickoff without inventing pregame lineage', function () {
    $home = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => Team::factory()->create()->id,
        'espn_event_id' => 'native-final-stats', 'status' => 'STATUS_FINAL', 'game_date' => '2027-01-13', 'game_time' => '00:00:00',
        'kickoff_observation' => CfbGameStart::observation('2027-01-13T00:00:00Z', ['timeValid' => false], 'espn_scoreboard', 'native-final-stats')]);
    $player = Player::factory()->create(['team_id' => $home->id, 'espn_id' => 'native-final-player', 'full_name' => 'Native final player']);
    $quote = PlayerProp::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => $player->full_name,
        'market' => 'player_pass_yds', 'line' => 0, 'fetched_at' => now()]);
    $stat = PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $player->id, 'team_id' => $home->id, 'passing_yards' => 0]);
    $before = [$quote->fresh()->getRawOriginal(), $stat->fresh()->getRawOriginal()];
    $rows = app(LivePropProjector::class)->project($game, null, null, true);
    expect($rows)->toHaveCount(1)->and($rows[0]['actual'])->toBe(0.0)->and($rows[0]['projected'])->toBe(0.0)
        ->and($rows[0]['status'])->toBe('final')->and($rows[0]['pregame_prop_id'])->toBeNull()->and($rows[0]['pregame_line'])->toBeNull()
        ->and($rows[0]['quotes'])->toBe([])->and([$quote->fresh()->getRawOriginal(), $stat->fresh()->getRawOriginal()])->toBe($before);
});
