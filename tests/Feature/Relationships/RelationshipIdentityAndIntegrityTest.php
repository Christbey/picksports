<?php

use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\GameOddsSnapshot;
use App\Models\LivePredictionSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\StartingPitcherForecast;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\OAuthUser;
use App\Models\PlayerProp;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Models\User;
use App\Services\Relationships\RelationshipIntegrityAudit;
use App\Services\Relationships\RelationshipInventory;
use App\Services\Relationships\SportRecordResolver;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;

it('resolves exact game identity and rejects sport-table mismatch before querying', function () {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $resolver = app(SportRecordResolver::class);
    expect($resolver->game('nfl', 'nfl_games', $game->id)?->is($game))->toBeTrue()
        ->and($resolver->game('nfl', 'nfl_games', 9999999))->toBeNull();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $resolver->game('nba', 'nfl_games', $game->id))->toThrow(InvalidArgumentException::class)
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('separates canonical public IDs from internal IDs and scopes forecasts by sport', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id, 'detail_sport' => 'nfl']);
    $resolver = app(SportRecordResolver::class);

    expect($resolver->prediction('nfl', 'predictions', $prediction->id)?->is($prediction))->toBeTrue()
        ->and($resolver->prediction('nba', 'predictions', $prediction->id))->toBeNull()
        ->and($resolver->publicPrediction('nfl', $prediction->public_id)?->is($prediction))->toBeTrue()
        ->and($resolver->publicPrediction('nba', $prediction->public_id))->toBeNull()
        ->and(fn () => $resolver->prediction('nfl', 'predictions', $prediction->public_id))->toThrow(InvalidArgumentException::class);
});

it('preserves recorded historical team ownership after a current roster transfer', function () {
    $teams = Team::factory()->count(3)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $player = Player::factory()->create(['team_id' => $teams[0]->id]);
    $stat = PlayerStat::factory()->create(['game_id' => $game->id, 'team_id' => $teams[0]->id, 'player_id' => $player->id]);
    $injury = PlayerInjury::query()->create(['player_id' => $player->id, 'team_id' => $teams[0]->id, 'injury_key' => 'historical-transfer', 'status' => 'Out', 'is_active' => false]);
    $player->update(['team_id' => $teams[2]->id]);

    expect($stat->fresh(['team', 'player.team'])->team->is($teams[0]))->toBeTrue()
        ->and($stat->fresh(['player.team'])->player->team->is($teams[2]))->toBeTrue()
        ->and($injury->fresh('team')->team->is($teams[0]))->toBeTrue();
});

it('resolves the MLB pitcher owner key as provider ID rather than internal player ID', function () {
    $player = App\Models\MLB\Player::factory()->create(['espn_id' => '99112233']);
    $forecast = new StartingPitcherForecast(['predicted_pitcher_espn_id' => '99112233']);

    expect($forecast->predictedPitcher?->is($player))->toBeTrue()
        ->and($forecast->predictedPitcher()->getOwnerKeyName())->toBe('espn_id');
});

it('reports event-sport mismatches without changing the offending row', function () {
    $teams = Team::factory()->count(2)->create();
    $event = SportEvent::factory()->create(['sport' => 'cfb']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $inventory = app(RelationshipInventory::class)->inspect();
    $result = app(RelationshipIntegrityAudit::class)->inspect([Game::class => $inventory[Game::class]]);

    expect($result[DB::connection()->getName().'::nfl_games']['event_sport_mismatch_count'])->toBe(1)
        ->and($game->fresh()->sport_event_id)->toBe($event->id);
});

it('counts pre-constraint duplicate and orphan fixtures read only', function () {
    Schema::create('relationship_audit_parents', function ($table) {
        $table->id();
    });
    Schema::create('relationship_audit_children', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('identity');
    });
    DB::table('relationship_audit_parents')->insert(['id' => 1]);
    DB::table('relationship_audit_children')->insert([
        ['parent_id' => 1, 'identity' => 'repeated'],
        ['parent_id' => 99, 'identity' => 'repeated'],
        ['parent_id' => null, 'identity' => 'nullable'],
    ]);
    // Candidate metadata models a schema before adding constraints, so unsafe data is observable.
    $definition = [
        'table' => 'relationship_audit_children', 'connection' => null, 'table_exists' => true,
        'columns' => [['name' => 'id'], ['name' => 'parent_id'], ['name' => 'identity']],
        'foreign_keys' => [['columns' => ['parent_id'], 'foreign_table' => 'relationship_audit_parents', 'foreign_columns' => ['id']]],
        'indexes' => [['name' => 'candidate_identity_unique', 'columns' => ['identity'], 'unique' => true]],
    ];
    $result = app(RelationshipIntegrityAudit::class)->inspect(['Fixture' => $definition])[DB::connection()->getName().'::relationship_audit_children'];

    expect($result['foreign_keys'][0]['orphan_count'])->toBe(1)
        ->and($result['unique_indexes'][0]['duplicate_group_count'])->toBe(1)
        ->and(DB::table('relationship_audit_children')->count())->toBe(3);
});

it('uses Passport owner identity for the deprecated OAuth client collection', function () {
    $user = User::factory()->create();
    $projection = OAuthUser::query()->findOrFail($user->id);
    $client = Client::query()->create([
        'name' => 'Owned client', 'owner_id' => $user->id, 'owner_type' => OAuthUser::class,
        'redirect_uris' => [], 'grant_types' => ['authorization_code'], 'revoked' => false,
    ]);
    Client::query()->create([
        'name' => 'Different owner type', 'owner_id' => $user->id, 'owner_type' => User::class,
        'redirect_uris' => [], 'grant_types' => ['authorization_code'], 'revoked' => false,
    ]);

    expect($projection->clients()->sole()->is($client))->toBeTrue()
        ->and($projection->oauthApps()->sole()->is($client))->toBeTrue()
        ->and($projection->load('clients')->clients->sole()->is($client))->toBeTrue();
});

it('refuses cross-sport event synchronization before any write', function () {
    $game = App\Models\MLB\Game::factory()->create(['home_team_id' => App\Models\MLB\Team::factory(), 'away_team_id' => App\Models\MLB\Team::factory()]);
    $count = SportEvent::query()->count();

    expect(fn () => app(SportEventIdentitySynchronizer::class)->sync('nfl', $game))->toThrow(InvalidArgumentException::class)
        ->and(SportEvent::query()->count())->toBe($count)
        ->and($game->fresh()->sport_event_id)->toBeNull();
});

it('resolves provider identities and canonical detail only within their explicit sport contracts', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    SportEventProviderMapping::factory()->create(['sport_event_id' => $event->id, 'provider' => 'espn', 'provider_event_id' => 'external-event']);
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id]);
    $canonical = CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nfl', 'detail_sport' => 'nfl', 'detail_id' => $legacy->id]);
    $resolver = app(SportRecordResolver::class);

    expect($resolver->eventForProvider('nfl', 'espn', 'external-event')?->is($event))->toBeTrue()
        ->and($resolver->eventForProvider('nba', 'espn', 'external-event'))->toBeNull()
        ->and($resolver->eventForProvider('nfl', 'espn', 'missing-event'))->toBeNull()
        ->and($resolver->legacyDetail($canonical)?->is($legacy))->toBeTrue();
    $canonical->detail_sport = 'nba';
    expect(fn () => $resolver->legacyDetail($canonical))->toThrow(InvalidArgumentException::class);
});

it('uses explicit game reference families without cross-table fallback', function (string $referenceClass) {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $reference = new $referenceClass;
    $reference->forceFill(['sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id]);
    $resolver = app(SportRecordResolver::class);

    expect(Schema::hasColumns($reference->getTable(), ['sport', 'game_table', 'game_id']))->toBeTrue()
        ->and($resolver->gameReference($reference)?->is($game))->toBeTrue();
    $reference->setAttribute('game_table', 'nba_games');
    expect(fn () => $resolver->gameReference($reference))->toThrow(InvalidArgumentException::class);
})->with([
    GameOddsSnapshot::class,
    MarketQuote::class,
    BetDecision::class,
    ShadowModelOutput::class,
    LivePredictionSnapshot::class,
]);

it('resolves recorded prediction table references without changing their source family', function (string $referenceClass) {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $reference = new $referenceClass;
    $reference->forceFill(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => $prediction->id]);
    $resolver = app(SportRecordResolver::class);

    expect(Schema::hasColumns($reference->getTable(), ['sport', 'prediction_table', 'prediction_id']))->toBeTrue()
        ->and($resolver->predictionReference($reference)?->is($prediction))->toBeTrue();
    $reference->setAttribute('prediction_table', 'nba_predictions');
    expect(fn () => $resolver->predictionReference($reference))->toThrow(InvalidArgumentException::class);
})->with([
    BetDecision::class,
    PredictionFeatureSnapshot::class,
    PredictionEvaluation::class,
    ShadowModelOutput::class,
]);

it('rejects unknown alert or delivery source classes and does not guess their models', function () {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $resolver = app(SportRecordResolver::class);

    expect($resolver->predictionSource('nfl', Prediction::class, $prediction->id)?->is($prediction))->toBeTrue()
        ->and($resolver->predictionSource('nfl', 'Vendor\\Untrusted\\Prediction', $prediction->id))->toBeNull()
        ->and(fn () => $resolver->predictionSource('nba', Prediction::class, $prediction->id))->toThrow(InvalidArgumentException::class);
});

it('keeps legacy generic props inside their provider-sport morph contract', function () {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $valid = new PlayerProp(['sport' => 'americanfootball_nfl', 'gameable_type' => Game::class, 'gameable_id' => $game->id]);
    $wrongSport = new PlayerProp(['sport' => 'basketball_nba', 'gameable_type' => Game::class, 'gameable_id' => $game->id]);
    $unknown = new PlayerProp(['sport' => 'americanfootball_nfl', 'gameable_type' => 'Vendor\\Untrusted\\Game', 'gameable_id' => $game->id]);
    (new Collection([$valid, $wrongSport, $unknown]))->load('gameable');

    expect($valid->gameable?->is($game))->toBeTrue()
        ->and($wrongSport->gameable)->toBeNull()
        ->and($unknown->gameable)->toBeNull()
        ->and((new PlayerProp(['sport' => 'unknown', 'gameable_type' => Game::class, 'gameable_id' => $game->id]))->gameable)->toBeNull();
});

it('counts invalid dynamic game references without resolving arbitrary table names', function () {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    foreach ([['nfl_games', $game->id], ['nfl_games', 999999], ['untrusted_table', $game->id]] as [$table, $id]) {
        GameOddsSnapshot::query()->create([
            'sport' => 'nfl', 'game_table' => $table, 'game_id' => $id, 'source' => 'fixture',
            'captured_at' => now(), 'payload_hash' => hash('sha256', $table.$id), 'odds_data' => [],
        ]);
    }
    $inventory = app(RelationshipInventory::class)->inspect();
    $result = app(RelationshipIntegrityAudit::class)->inspect([GameOddsSnapshot::class => $inventory[GameOddsSnapshot::class]])[DB::connection()->getName().'::game_odds_snapshots'];

    expect($result['dynamic_game_references'])->toBe(['invalid_type_count' => 1, 'orphan_count' => 1])
        ->and(GameOddsSnapshot::query()->count())->toBe(3);
});
