<?php

use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\PlayerProp as NbaProp;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\PlayerProp as NflProp;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use App\Services\BettingRecommendations\NflPropCalendarRead;
use App\Services\BettingRecommendations\NflPropGameTime;
use App\Support\SportsViewCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['app.timezone' => 'America/Chicago', 'sports.business_timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26T12:00:00-06:00'));
    $user = User::factory()->admin()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
});

function propMissingClockMetadata(): array
{
    return ['event_id' => 'nflverse:synthetic-prop-clock', 'commence_time' => null, 'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']];
}

it('does not turn an identified old NFL missing clock into a prop kickoff label', function () {
    $game = new NflGame(['game_date' => '2026-11-27', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-prop-clock', 'odds_data' => propMissingClockMetadata()]);
    $game->syncOriginal();
    $schedule = app(NflPropGameTime::class)->forGame($game);
    expect([$schedule['date'], $schedule['starts_at'], $schedule['kickoff_label']])->toBe(['2026-11-27', null, 'Time TBD']);
});

it('retains the calendar date in NFL prop date options when the old source proves a missing clock', function () {
    $game = NflGame::factory()->create(['home_team_id' => NflTeam::factory(), 'away_team_id' => NflTeam::factory(), 'game_date' => '2026-11-27', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-prop-clock', 'odds_data' => propMissingClockMetadata()]);
    NflProp::query()->create(['game_id' => $game->id, 'player_name' => 'Synthetic Player', 'market' => 'player_pass_yds', 'bookmaker' => 'synthetic', 'line' => 0, 'fetched_at' => now()]);
    $this->getJson('/api/v2/sports/nfl/player-props/board?date=2026-11-27')->assertOk()->assertJsonPath('dates.0.value', '2026-11-27')->assertJsonPath('games.0.starts_at', null);
});

function propNullableLine(?float $line): NbaProp
{
    $home = NbaTeam::factory()->create();
    $game = NbaGame::factory()->create(['home_team_id' => $home->id, 'away_team_id' => NbaTeam::factory(), 'game_date' => '2026-11-27', 'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED']);
    $player = NbaPlayer::factory()->create(['team_id' => $home->id]);

    return NbaProp::query()->create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => 'Synthetic Player', 'market' => 'player_points', 'bookmaker' => 'synthetic', 'line' => $line, 'over_price' => -110, 'under_price' => -110, 'recommended_side' => 'Over', 'confidence_score' => 70, 'fetched_at' => now(), 'confidence_decomposition' => ['schema_version' => 'player-prop-signal-v2', 'stat_summary' => ['season_avg' => 10, 'recent_avg' => 11, 'last5_avg' => 12], 'cover_record' => []]]);
}

it('does not publish a zero-line recommendation from a missing stored prop line', function () {
    propNullableLine(null);
    $this->getJson('/api/v2/sports/nba/player-props/board?date=2026-11-27')->assertOk()->assertJsonCount(0, 'data');
});

it('preserves a real zero prop line', function () {
    $known = propNullableLine(0);
    $this->getJson('/api/v2/sports/nba/player-props/board?date=2026-11-27')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $known->id)->assertJsonPath('data.0.line', 0);
});

it('preserves a raw quote missing line as null', function () {
    $missing = propNullableLine(null);
    $this->getJson('/api/v2/sports/nba/player-props?game_id='.$missing->game_id)->assertOk()->assertJsonPath('data.0.line', null);
});

it('preserves an imported explicit real midnight instead of classifying every zero clock as missing', function () {
    $metadata = propMissingClockMetadata();
    $metadata['commence_time'] = '2026-11-27T00:00:00Z';
    $game = new NflGame(['game_date' => '2026-11-27', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-prop-clock', 'odds_data' => $metadata]);
    $game->syncOriginal();
    $schedule = app(NflPropGameTime::class)->forGame($game);
    expect($schedule['starts_at'])->toBe('2026-11-27T00:00:00+00:00');
});

it('does not reuse pre-observation-policy board cache or alter stored statistics when a line is missing', function () {
    $missing = propNullableLine(null);
    $before = $missing->fresh()->getRawOriginal();
    $cache = app(SportsViewCache::class);
    $key = $cache->contextHash(['sport' => 'nba', 'date' => '2026-11-27', 'game' => null, 'market' => null, 'limit' => 75]);
    $old = ['sport' => 'NBA', 'data' => [['id' => $missing->id, 'line' => 0]]];
    $cache->remember(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE, $key, 300, fn () => $old);
    $this->getJson('/api/v2/sports/nba/player-props/board?date=2026-11-27')->assertOk()->assertJsonCount(0, 'data');
    expect($missing->fresh()->getRawOriginal())->toBe($before)
        ->and($missing->fresh()->confidence_decomposition['stat_summary']['season_avg'])->toBe(10)
        ->and($cache->remember(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE, $key, 300, fn () => []))->toBe($old);
});

it('classifies an imported real midnight using date-option metadata rather than dropping the marker projection', function () {
    $metadata = propMissingClockMetadata();
    $metadata['commence_time'] = '2026-11-27T00:00:00Z';
    $game = NflGame::factory()->create(['home_team_id' => NflTeam::factory(), 'away_team_id' => NflTeam::factory(), 'game_date' => '2026-11-27', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-prop-clock', 'odds_data' => $metadata]);
    NflProp::query()->create(['game_id' => $game->id, 'player_name' => 'Synthetic Player', 'market' => 'player_pass_yds', 'bookmaker' => 'synthetic', 'line' => 0, 'fetched_at' => now()]);
    $this->getJson('/api/v2/sports/nfl/player-props/board?date=2026-11-26')->assertOk()->assertJsonPath('dates.0.value', '2026-11-26')->assertJsonPath('games.0.starts_at', '2026-11-27T00:00:00+00:00');
});

it('keeps NFL calendar projection and date predicates on the supplied named connection', function () {
    $poison = NflGame::factory()->create(['home_team_id' => NflTeam::factory(), 'away_team_id' => NflTeam::factory(), 'game_date' => '2026-11-28', 'game_time' => '17:00:00']);
    config(['database.connections.prop_calendar_named' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
    $connection = DB::connection('prop_calendar_named');
    try {
        $connection->getSchemaBuilder()->create('nfl_games', function (Blueprint $table): void {
            $table->id();
            $table->date('game_date');
            $table->time('game_time')->nullable();
            $table->string('nflverse_game_id')->nullable();
            $table->json('odds_data')->nullable();
        });
        $connection->table('nfl_games')->insert(['id' => $poison->id, 'game_date' => '2026-11-27', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-prop-clock', 'odds_data' => json_encode(propMissingClockMetadata(), JSON_THROW_ON_ERROR)]);
        $calendar = app(NflPropCalendarRead::class);
        expect($calendar->datesFor(NflGame::on('prop_calendar_named'))->all())->toBe(['2026-11-27'])
            ->and($calendar->onDate(NflGame::on('prop_calendar_named'), '2026-11-27')->pluck('id')->all())->toBe([$poison->id]);
        expect($poison->fresh()->getRawOriginal('game_date'))->toStartWith('2026-11-28');
    } finally {
        DB::purge('prop_calendar_named');
        config(['database.connections.prop_calendar_named' => null]);
    }
});

it('projects oversized imported clock metadata as unavailable before model JSON hydration', function () {
    $metadata = [...propMissingClockMetadata(), 'padding' => str_repeat('x', 65_536)];
    $game = NflGame::factory()->create(['home_team_id' => NflTeam::factory(), 'away_team_id' => NflTeam::factory(), 'game_date' => '2026-11-27', 'game_time' => '00:00:00', 'nflverse_game_id' => 'synthetic-prop-clock', 'odds_data' => $metadata]);
    $raw = $game->getRawOriginal('odds_data');
    expect(strlen($raw))->toBeGreaterThan(65_536);
    $original = Model::getEventDispatcher();
    $probe = clone $original;
    $sizes = [];
    $flags = [];
    $probe->listen('eloquent.retrieved: '.NflGame::class, function (NflGame $row) use (&$sizes, &$flags): void {
        $sizes[] = strlen((string) $row->getRawOriginal('odds_data'));
        $flags[] = (int) ($row->getAttributes()['read_clock_metadata_unavailable'] ?? 0);
    });
    Model::setEventDispatcher($probe);
    try {
        $dates = app(NflPropCalendarRead::class)->datesFor(NflGame::query()->whereKey($game->id));
    } finally {
        Model::setEventDispatcher($original);
    }
    expect($dates->all())->toBe(['2026-11-27'])->and($sizes)->toBe([0])->and($flags)->toBe([1]);
    expect(app(NflPropGameTime::class)->forGame($game)['starts_at'])->toBeNull()
        ->and($game->fresh()->getRawOriginal('odds_data'))->toBe($raw);
});

it('preserves an explicit nonzero clock when unrelated metadata exceeds the projection budget', function () {
    $game = new NflGame(['game_date' => '2026-11-27', 'game_time' => '17:00:00', 'nflverse_game_id' => 'synthetic-prop-clock', 'odds_data' => ['padding' => str_repeat('x', 65_536)]]);
    $game->syncOriginal();
    expect(app(NflPropGameTime::class)->forGame($game)['starts_at'])->toBe('2026-11-27T17:00:00+00:00');
});
