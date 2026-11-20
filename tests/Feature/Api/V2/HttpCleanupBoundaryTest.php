<?php

use App\Application\Sports\ReadModels\CanonicalPredictionSummaryMapper;
use App\Application\Sports\ReadModels\GameSummaryMapper;
use App\Http\Resources\Api\V2\SportGameResource;
use App\Http\Resources\Api\V2\SportPlayerResource;
use App\Http\Resources\Api\V2\SportStatResource;
use App\Http\Resources\BettingRecommendationResource;
use App\Http\Resources\NBA\TeamResource;
use App\Http\Resources\NFL\GameResource;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\PlayerInjury as NbaInjury;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Api\V2\MlbGamePageData;
use App\Services\Api\V2\PreparedInjuryImpacts;
use App\Services\Api\V2\SelectedGameForecasts;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\Api\V2\SportQuerySchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function httpCleanupGame(): Game
{
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'starts_at' => now()->addDay()]);

    return Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->addDay()->utc()->toDateString(), 'game_time' => '20:15:00']);
}

function httpCleanupCanonical(Game $game, bool $published = true): CanonicalPrediction
{
    $snapshot = EventInputSnapshot::factory()->create(['sport' => 'nfl', 'sport_event_id' => $game->sport_event_id]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $game->sport_event_id,
        'event_input_snapshot_id' => $snapshot->id, 'status' => 'succeeded']);

    return CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $game->sport_event_id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => $published ? 'published' : 'draft', 'published_at' => $published ? now() : null,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
}

it('remembers a missing selected forecast without falling back or selecting it again', function () {
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $game = httpCleanupGame();
    Prediction::factory()->create(['game_id' => $game->id]);
    $context = app(SportContextResolver::class)->resolve('nfl');
    $loaded = app(SportGameQuery::class)->find($context, $game->id);
    expect(app(SelectedGameForecasts::class)->contains($loaded))->toBeTrue()
        ->and($loaded->relationLoaded('prediction'))->toBeFalse();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(SportPredictionReader::class)->forLoadedGame($context, $loaded))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('reuses a gated canonical forecast without changing the legacy relationship model', function () {
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $game = httpCleanupGame();
    $legacy = Prediction::factory()->create(['game_id' => $game->id]);
    $canonical = httpCleanupCanonical($game);
    $context = app(SportContextResolver::class)->resolve('nfl');
    $loaded = app(SportGameQuery::class)->find($context, $game->id);
    expect($loaded->relationLoaded('prediction'))->toBeFalse()
        ->and($loaded->prediction()->first()->id)->toBe($legacy->id)
        ->and(app(SelectedGameForecasts::class)->get($loaded)->id)->toBe($canonical->id);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $payload = app(SportPredictionReader::class)->forLoadedGame($context, $loaded);
    $json = $payload['data']->resolve(Request::create('/'));
    // Main retains a separate approval EXISTS gate; the selected forecast row must not reload.
    $reads = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "predictions"')
        && ! str_starts_with(strtolower(ltrim($sql)), 'select exists('));
    DB::disableQueryLog();
    expect($json['id'])->toBe($canonical->public_id)->and($reads)->toHaveCount(0);
});

it('keeps NFL start timestamps equal for model and summary representations', function () {
    $game = httpCleanupGame();
    $context = app(SportContextResolver::class)->resolve('nfl');
    $request = Request::create('/');
    $model = (new SportGameResource($game, $context))->resolve($request);
    $summary = (new SportGameResource(app(GameSummaryMapper::class)->fromModel($context, $game), $context))->resolve($request);
    expect($summary['starts_at'])->toBe($model['starts_at'])->not->toBeNull();
});

it('uses the same honest missing probability semantics in both canonical summary paths', function () {
    $game = httpCleanupGame();
    $canonical = httpCleanupCanonical($game, false);
    PredictionMarket::factory()->create(['prediction_id' => $canonical->id,
        'market_type' => 'spread', 'selection' => 'home', 'confidence_score' => 71]);
    $canonical->load('markets');
    $dedicated = app(CanonicalPredictionSummaryMapper::class)->fromModel($canonical);
    $summary = app(GameSummaryMapper::class)->fromModel(app(SportContextResolver::class)->resolve('nfl'), $game, $canonical);
    expect($summary->prediction)->toEqual($dedicated)
        ->and($dedicated->homeWinProbability)->toBeNull()
        ->and($dedicated->confidenceScore)->toBeNull();
});

it('passes matchup presentation context without dirtying persistence attributes', function () {
    $game = httpCleanupGame();
    $context = app(SportContextResolver::class)->resolve('nfl');
    $payload = (new SportGameResource($game, $context, ['rows' => [['label' => 'fixture']]]))->resolve(Request::create('/'));
    expect($payload['matchup_context']['rows'][0]['label'])->toBe('fixture')
        ->and($game->isDirty())->toBeFalse()
        ->and(array_key_exists('matchup_context', $game->getAttributes()))->toBeFalse();
});

it('serializes deliberately public casted stats without publishing incidental columns', function () {
    $stat = new PlayerStat;
    $stat->forceFill(['id' => 1, 'passing_yards' => 203, 'passing_completion_pct' => '66.700', 'internal_debug_payload' => 'secret']);
    $payload = (new SportStatResource($stat, app(SportContextResolver::class)->resolve('nfl'), 'player'))->resolve(Request::create('/'));
    expect($payload['stats']['passing_yards'])->toBe(203)
        ->and($payload['stats'])->not->toHaveKey('internal_debug_payload');
});

it('keeps declared query capabilities aligned with migrated sports tables', function () {
    foreach (SportQuerySchema::COLUMNS as $table => $columns) {
        expect($columns)->toBe(array_values(array_intersect(Schema::getColumnListing($table), SportQuerySchema::SUPPORTED_COLUMNS)));
    }
});

it('batches loaded injury preparation and serializes impacts without queries', function () {
    $team = NbaTeam::factory()->create();
    foreach (NbaPlayer::factory()->count(5)->create(['team_id' => $team->id]) as $player) {
        NbaInjury::create(['player_id' => $player->id, 'team_id' => $team->id, 'injury_key' => 'cleanup-'.$player->id,
            'status' => 'Out', 'is_active' => true]);
    }
    $team->load('activePlayerInjuries');
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(PreparedInjuryImpacts::class)->prepare([$team]);
    $prep = DB::getQueryLog();
    DB::flushQueryLog();
    $payload = (new TeamResource($team))->response()->getData(true);
    $serialization = DB::getQueryLog();
    DB::disableQueryLog();
    expect($payload['data']['active_injuries'])->toHaveCount(5)
        ->and(collect($prep)->filter(fn ($row) => str_contains($row['query'], 'from "nba_players"')))->toHaveCount(1)
        ->and($serialization)->toBe([]);
});

it('honors computed public accessors that have no backing attribute', function () {
    $player = new class extends Model
    {
        public function getFullNameAttribute(): string
        {
            return 'Computed Player';
        }
    };
    $payload = (new SportPlayerResource($player,
        app(SportContextResolver::class)->resolve('nfl')))->resolve(Request::create('/'));
    expect($payload['full_name'])->toBe('Computed Player')
        ->and(array_key_exists('full_name', $player->getAttributes()))->toBeFalse();
});

it('retains query-free estimated EPA arithmetic in legacy stat resources', function (string $sport) {
    $model = 'App\\Models\\'.$sport.'\\PlayerStat';
    $resource = 'App\\Http\\Resources\\'.$sport.'\\PlayerStatResource';
    $stat = new $model;
    $stat->forceFill(['id' => 1, 'passing_yards' => 210, 'passing_attempts' => 30, 'passing_completions' => 20,
        'rushing_yards' => 20, 'rushing_attempts' => 3, 'points' => 20, 'assists' => 4, 'rebounds_total' => 6,
        'field_goals_made' => 7, 'field_goals_attempted' => 14, 'free_throws_made' => 4, 'free_throws_attempted' => 4,
        'minutes_played' => '32', 'steals' => 1, 'blocks' => 1, 'turnovers' => 2]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $payload = (new $resource($stat))->resolve(Request::create('/'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($payload)->toHaveKey('estimated_epa')->and($queries)->toBe([]);
})->with(['NFL', 'NBA', 'CBB']);

it('serializes NFL live game estimates only from explicit prepared input', function () {
    $game = httpCleanupGame();
    $estimate = ['home_win_probability' => 0.71, 'away_win_probability' => 0.29,
        'is_live' => true, 'seconds_remaining' => 900, 'margin' => 7];
    DB::enableQueryLog();
    DB::flushQueryLog();
    $payload = (new GameResource($game, $estimate))->resolve(Request::create('/'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($payload['live_win_probability'])->toBe($estimate)->and($queries)->toBe([]);
});

it('does not query a season summary while serializing an NFL recommendation without one', function () {
    $prop = new PlayerProp(['player_id' => 42, 'player_name' => 'Stored Player', 'line' => 22.5]);
    $game = new Game(['game_date' => '2026-11-17', 'game_time' => '23:00:00']);
    $game->setRelation('homeTeam', null)->setRelation('awayTeam', null);
    $resource = new BettingRecommendationResource([
        'prop' => $prop, 'game' => $game, 'market' => 'Passing yards', 'line' => 22.5,
        'recommendation' => 'Over', 'odds' => -110, 'confidence' => 70,
        'season_avg' => 24, 'recent_avg' => 24, 'last5_avg' => 24, 'edge' => 1.5, 'reasoning' => [],
    ]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $resource->resolve(Request::create('/'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toBe([]);
});

it('fails explicitly for undeclared optional query capabilities instead of dropping a filter', function () {
    expect(fn () => SportQuerySchema::hasColumn('mlb_player_stats', 'new_undeclared_filter'))
        ->toThrow(LogicException::class, 'Undeclared optional sport query column');
    expect(SportQuerySchema::hasColumn('mlb_player_stats', 'stat_type'))->toBeTrue()
        ->and(SportQuerySchema::hasColumn('nfl_player_stats', 'stat_type'))->toBeFalse();
});

it('hydrates both recent-game instances when MLB opponents share a previous matchup', function () {
    $home = App\Models\MLB\Team::factory()->create();
    $away = App\Models\MLB\Team::factory()->create();
    $game = App\Models\MLB\Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL',
    ]);
    $context = app(SportContextResolver::class)->resolve('mlb');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $recent = app(MlbGamePageData::class)->recentGames(
        $context, app(SportGameQuery::class), ['status' => 'STATUS_FINAL'],
        $home->id, $away->id, Request::create('/'),
    );
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (['home', 'away'] as $side) {
        expect($recent[$side][0]['id'])->toBe($game->id)
            ->and($recent[$side][0]['home_team']['id'] ?? null)->toBe($home->id)
            ->and($recent[$side][0]['away_team']['id'] ?? null)->toBe($away->id);
    }
    expect($queries->filter(fn ($query) => str_contains($query['query'], 'from "mlb_teams"')))->toHaveCount(1);
});
