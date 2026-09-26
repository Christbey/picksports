<?php

use App\Http\Middleware\UpdateUserLastActive;
use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\User;
use App\Services\NFL\NflHistoricalMarketEvidence;
use App\Support\SportsViewCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 21)->setTime(10, 0));
    config(['subscriptions.enforce_tiers' => false, 'cache.default' => 'array', 'app.timezone' => 'UTC']);
    app(SportsViewCache::class)->bustAll();
});

function marketEvidenceGame(array $attributes = []): Game
{
    return Game::factory()->create(array_replace([
        'home_team_id' => $attributes['home_team_id'] ?? Team::factory()->create(['location' => 'Home', 'name' => 'Team'])->id,
        'away_team_id' => $attributes['away_team_id'] ?? Team::factory()->create(['location' => 'Away', 'name' => 'Team'])->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-09-27', 'game_time' => '17:00:00',
        'status' => 'STATUS_SCHEDULED', 'home_score' => null, 'away_score' => null, 'neutral_site' => false,
        'home_coach' => 'Coach One', 'home_qb_id' => 'gsis-1', 'home_qb_name' => 'QB One',
        'away_coach' => null, 'away_qb_id' => null, 'away_qb_name' => null,
    ], $attributes));
}

function marketEvidenceArchive(Game $game, float $rawHomeMargin, array $attributes = []): GameOddsSnapshot
{
    return GameOddsSnapshot::query()->create(array_replace([
        'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
        'source' => 'nflverse', 'bookmaker_key' => 'nflverse_closing',
        'captured_at' => '2026-07-01', 'payload_hash' => fake()->uuid(),
        'market_context' => ['source' => 'nflverse_schedules', 'line_type' => 'closing', 'spread_line' => $rawHomeMargin],
        // Deliberately wrong old payload sign: the raw source value is authoritative.
        'odds_data' => ['bookmakers' => [['markets' => [['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => $rawHomeMargin]]]]]]],
    ], $attributes));
}

function marketEvidencePrior(Game $target, int $homeScore, int $awayScore, array $attributes = []): Game
{
    return marketEvidenceGame(array_replace([
        'season' => 2025, 'game_date' => '2025-09-15', 'status' => 'STATUS_FINAL',
        'home_team_id' => $target->home_team_id, 'away_team_id' => $target->away_team_id,
        'home_score' => $homeScore, 'away_score' => $awayScore,
    ], $attributes));
}

function marketEvidenceRows(array $data, string $side = 'home'): Collection
{
    return collect($data['sides'][$side]['rows'])->keyBy('id');
}

it('grades exact signed historical lines with wins losses pushes and outright ties', function () {
    $target = marketEvidenceGame();
    foreach ([[24, 14], [20, 17], [17, 17]] as [$home, $away]) {
        marketEvidenceArchive(marketEvidencePrior($target, $home, $away), 3);
    }
    marketEvidenceArchive(marketEvidencePrior($target, 50, 0), 3.5); // Never pool a nearby line.
    marketEvidenceArchive(marketEvidencePrior($target, 50, 0), -3); // Opposite handicap.
    $data = app(NflHistoricalMarketEvidence::class)->build($target, 2009, -3);
    $row = marketEvidenceRows($data)['team'];
    expect($data['affects_prediction'])->toBeFalse()
        ->and($data['market']['source'])->toBe('user_selected')
        ->and($row['sample_size'])->toBe(3)
        ->and($row['ats'])->toBe(['wins' => 1, 'losses' => 1, 'pushes' => 1])
        ->and($row['outright'])->toBe(['wins' => 2, 'losses' => 0, 'ties' => 1])
        ->and($row['ats_win_pct'])->toBe(50.0)
        ->and($row['average_margin'])->toBe(4.33)
        ->and($row['average_cover_margin'])->toBe(1.33)
        ->and($row['small_sample'])->toBeTrue();
    $away = marketEvidenceRows($data, 'away')['team'];
    expect($away['ats'])->toBe(['wins' => 1, 'losses' => 1, 'pushes' => 1])
        ->and($away['outright'])->toBe(['wins' => 0, 'losses' => 2, 'ties' => 1]);
});

it('deduplicates identical archive lines and excludes missing invalid and conflicting source evidence', function () {
    $target = marketEvidenceGame();
    $valid = marketEvidencePrior($target, 24, 10);
    $first = marketEvidenceArchive($valid, 3);
    marketEvidenceArchive($valid, 3);
    $conflict = marketEvidencePrior($target, 24, 10);
    marketEvidenceArchive($conflict, 3);
    marketEvidenceArchive($conflict, 7);
    $missing = marketEvidencePrior($target, 24, 10);
    marketEvidenceArchive($missing, 3, ['source' => 'odds_api']);
    marketEvidenceArchive(marketEvidencePrior($target, 24, 10), 3, ['market_context' => ['spread_line' => 3]]);
    marketEvidenceArchive(marketEvidencePrior($target, 24, 10), 3.25);
    $data = app(NflHistoricalMarketEvidence::class)->build($target, 2009, -3);
    expect($data['coverage']['games_with_verified_line'])->toBe(1)
        ->and($data['coverage']['excluded_missing_or_conflicting_line'])->toBe(4)
        ->and(marketEvidenceRows($data)['team']['game_ids'])->toBe([$valid->id])
        ->and(marketEvidenceRows($data)['team']['snapshot_ids'])->toBe([$first->id]);
});

it('bounds history by season and date without leaking current same day or later results', function () {
    $target = marketEvidenceGame(['game_date' => '2026-09-13', 'status' => 'STATUS_FINAL', 'home_score' => 40, 'away_score' => 0]);
    $valid = marketEvidencePrior($target, 24, 10);
    marketEvidenceArchive($valid, 3);
    marketEvidenceArchive($target, 3);
    foreach ([['game_date' => '2026-09-13'], ['game_date' => '2026-09-14'],
        ['season' => 2008, 'game_date' => '2008-09-01'], ['season_type' => '3'],
        ['season_type' => '1'], ['home_score' => null], ['status' => 'STATUS_IN_PROGRESS']] as $attributes) {
        marketEvidenceArchive(marketEvidencePrior($target, 24, 10, $attributes), 3);
    }
    $data = app(NflHistoricalMarketEvidence::class)->build($target);
    expect($data['market']['source'])->toBe('historical_closing_archive')
        ->and($data['market']['home_line'])->toBe(-3.0)
        ->and($data['market']['observed_at'])->toBeNull()
        ->and(marketEvidenceRows($data)['team']['game_ids'])->toBe([$valid->id]);
});

it('separates all venue records from same venue and uses historical coach and QB identities', function () {
    $target = marketEvidenceGame();
    $both = marketEvidencePrior($target, 24, 10, ['home_coach' => '  COACH   ONE ']);
    $coach = marketEvidencePrior($target, 24, 10, ['home_qb_id' => 'gsis-2']);
    $qb = marketEvidencePrior($target, 24, 10, ['home_coach' => 'Other Coach']);
    $neutral = marketEvidencePrior($target, 24, 10, ['neutral_site' => true]);
    foreach ([$both, $coach, $qb, $neutral] as $game) {
        marketEvidenceArchive($game, 3);
    }
    $road = marketEvidencePrior($target, 10, 24, ['home_team_id' => $target->away_team_id, 'away_team_id' => $target->home_team_id]);
    marketEvidenceArchive($road, -3);
    $rows = marketEvidenceRows(app(NflHistoricalMarketEvidence::class)->build($target, 2009, -3));
    expect($rows['team']['sample_size'])->toBe(5)
        ->and($rows['team_venue']['sample_size'])->toBe(3)
        ->and($rows['coach']['game_ids'])->toBe([$both->id, $coach->id])
        ->and($rows['qb']['game_ids'])->toBe([$both->id, $qb->id])
        ->and($rows['coach_qb']['game_ids'])->toBe([$both->id]);
});

it('does not invent unknown coach QB or market and labels name-only matching', function () {
    $target = marketEvidenceGame(['home_coach' => null, 'home_qb_id' => null, 'home_qb_name' => null]);
    marketEvidenceArchive(marketEvidencePrior($target, 24, 10), 3);
    $data = app(NflHistoricalMarketEvidence::class)->build($target, 2009, -3);
    expect(marketEvidenceRows($data)['coach']['status'])->toBe('unavailable')
        ->and(marketEvidenceRows($data)['qb']['status'])->toBe('unavailable');
    $target->home_qb_name = 'QB One';
    $data = app(NflHistoricalMarketEvidence::class)->build($target, 2009, -3);
    expect($data['sides']['home']['qb_identity_source'])->toBe('game_exact_name')
        ->and($data['sides']['home']['identity_coverage'])->toBe(['cohort_games' => 1, 'coach_known_games' => 1, 'qb_known_games' => 1])
        ->and(marketEvidenceRows($data)['qb']['sample_size'])->toBe(1);
    $target->odds_data = ['home_spread' => -3];
    $data = app(NflHistoricalMarketEvidence::class)->build($target);
    expect($data['market'])->toBeNull()->and(marketEvidenceRows($data)['team']['status'])->toBe('unavailable');
});

it('makes pickem team appearances and non independent unique games explicit', function () {
    $target = marketEvidenceGame();
    marketEvidenceArchive(marketEvidencePrior($target, 17, 17), 0);
    marketEvidenceArchive(marketEvidencePrior($target, 24, 17), 0);
    $rows = marketEvidenceRows(app(NflHistoricalMarketEvidence::class)->build($target, 2009, 0));
    expect($rows['league']['sample_size'])->toBe(4)
        ->and($rows['league']['unique_games'])->toBe(2)
        ->and($rows['league']['ats'])->toBe(['wins' => 1, 'losses' => 1, 'pushes' => 2])
        ->and($rows['league_venue']['sample_size'])->toBe(2);
});

function marketEvidenceQuote(Game $game, array $attributes = []): GameOddsSnapshot
{
    return GameOddsSnapshot::query()->create(array_replace([
        'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
        'source' => 'odds_api', 'bookmaker_key' => 'testbook', 'bookmaker_title' => 'Test book',
        'captured_at' => '2026-09-21 09:00:00', 'commence_time' => '2026-09-27 17:00:00', 'payload_hash' => fake()->uuid(),
        'odds_data' => ['home_team' => 'Home Team', 'away_team' => 'Away Team', 'bookmakers' => [
            ['key' => 'testbook', 'markets' => [['key' => 'spreads', 'outcomes' => [
                ['name' => 'Home Team', 'point' => -3], ['name' => 'Away Team', 'point' => 3],
            ]]]],
        ]],
    ], $attributes));
}

it('selects a named observed pregame quote and excludes live future and mismatched event snapshots', function () {
    $target = marketEvidenceGame(['odds_api_event_id' => 'correct-event']);
    $valid = marketEvidenceQuote($target, ['odds_api_event_id' => 'correct-event']);
    marketEvidenceQuote($target, ['odds_api_event_id' => 'other-event', 'captured_at' => '2026-09-21 09:30:00']);
    marketEvidenceQuote($target, ['odds_api_event_id' => 'correct-event', 'captured_at' => '2026-09-28 00:00:00']);
    marketEvidenceQuote($target, ['odds_api_event_id' => 'correct-event', 'captured_at' => '2026-09-22 00:00:00']);
    marketEvidenceQuote($target, ['odds_api_event_id' => 'correct-event', 'commence_time' => '2026-09-20 00:00:00']);
    $data = app(NflHistoricalMarketEvidence::class)->build($target);
    expect($data['market']['snapshot_id'])->toBe($valid->id)
        ->and($data['market']['source'])->toBe('observed_pregame')
        ->and($data['market']['bookmaker'])->toBe('Test book')
        ->and($data['sides']['home']['line'])->toBe(-3.0)
        ->and($data['sides']['away']['line'])->toBe(3.0);
});

it('does not fall back to mutable or old quotes when the newest pregame payload has invalid identities', function () {
    $target = marketEvidenceGame();
    marketEvidenceQuote($target);
    marketEvidenceQuote($target, ['captured_at' => '2026-09-21 09:30:00', 'odds_data' => ['home_team' => 'Wrong team']]);
    expect(app(NflHistoricalMarketEvidence::class)->build($target)['market'])->toBeNull();
});

it('converts UTC kickoff to the snapshot storage timezone before selecting a quote', function () {
    config(['app.timezone' => 'America/Chicago']);
    $target = marketEvidenceGame(); // Game date/time contract is UTC.
    $quote = marketEvidenceQuote($target, ['commence_time' => '2026-09-27 12:00:00']);
    expect(app(NflHistoricalMarketEvidence::class)->build($target)['market']['snapshot_id'])->toBe($quote->id);
});

it('includes other teams in league records using bounded queries and no stat or play hydration', function () {
    $target = marketEvidenceGame();
    $other = marketEvidenceGame();
    for ($index = 0; $index < 20; $index++) {
        marketEvidenceArchive(marketEvidencePrior($other, 24, 10), 3);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = marketEvidenceRows(app(NflHistoricalMarketEvidence::class)->build($target, 2009, -3));
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($rows['league']['sample_size'])->toBe(20)
        ->and($rows['league']['small_sample'])->toBeFalse()
        ->and($rows['team']['status'])->toBe('no_sample')
        ->and($queries->count())->toBeLessThanOrEqual(5)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'nflverse_pbp_plays') || str_contains($sql, 'player_stats')))->toHaveCount(0);
});

it('keeps API evidence read only cached and isolated by line and starting season', function () {
    $this->withoutMiddleware(UpdateUserLastActive::class);
    Sanctum::actingAs(User::factory()->create());
    $target = marketEvidenceGame();
    marketEvidenceArchive(marketEvidencePrior($target, 24, 10), 3);
    Http::fake();
    $url = "/api/v2/sports/nfl/games/{$target->id}/market-history";
    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = $this->getJson($url.'?home_line=-3')->assertOk()->assertJsonPath('data.affects_prediction', false);
    $queries = collect(DB::getQueryLog())->pluck('query');
    expect($queries->filter(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql)))->toHaveCount(0);
    DB::flushQueryLog();
    $second = $this->getJson($url.'?home_line=-3')->assertOk();
    expect($second->json('data'))->toBe($first->json('data'))
        ->and(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'game_odds_snapshots')))->toHaveCount(0);
    DB::disableQueryLog();
    $this->getJson($url.'?home_line=-7')->assertOk()->assertJsonPath('data.sides.home.rows.2.sample_size', 0);
    $this->getJson($url.'?home_line=-3&since=2026')->assertOk()->assertJsonPath('data.coverage.completed_games', 0);
    foreach (['home_line=3.25', 'home_line=100', 'home_line=', 'since=2008', 'since=2027'] as $input) {
        $this->getJson($url.'?'.$input)->assertUnprocessable();
    }
    Http::assertNothingSent();
});

it('requires authentication and rejects wrong sport or missing games', function () {
    $this->getJson('/api/v2/sports/nfl/games/1/market-history')->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/nba/games/1/market-history')->assertNotFound();
    $this->getJson('/api/v2/sports/nfl/games/9999999/market-history')->assertNotFound();
});
