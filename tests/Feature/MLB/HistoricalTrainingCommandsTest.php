<?php

use App\Models\GameOddsSnapshot;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\ModelRun;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\MLB\TrustedHistoricalFeatureBuilder;
use App\Services\MLB\TrustedHistoricalPredictionReconstructor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses()->group('mlb', 'commands');

it('backfills historical mlb predictions and snapshots for completed regular-season games', function () {
    $home = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Cubs',
        'abbreviation' => 'CHC',
    ]);
    $away = Team::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
        'abbreviation' => 'STL',
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-06-15',
        'game_time' => '19:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 5,
        'away_score' => 3,
    ]);

    Artisan::call('mlb:backfill-historical-predictions', [
        '--season' => 2025,
        '--limit' => 1,
    ]);

    $prediction = Prediction::query()->where('game_id', $game->id)->first();
    $snapshot = PredictionFeatureSnapshot::query()
        ->where('prediction_table', 'mlb_predictions')
        ->where('game_id', $game->id)
        ->first();
    $evaluation = PredictionEvaluation::query()
        ->where('prediction_table', 'mlb_predictions')
        ->where('game_id', $game->id)
        ->first();

    expect($prediction)->not->toBeNull()
        ->and($prediction?->feature_version)->toBe('core-v3')
        ->and($prediction?->graded_at)->not->toBeNull()
        ->and($snapshot)->not->toBeNull()
        ->and($snapshot?->sport)->toBe('mlb')
        ->and($evaluation)->not->toBeNull()
        ->and($evaluation?->sport)->toBe('mlb');
});

it('creates verified trusted-core reconstruction snapshots with matching evaluation lineage', function () {
    $home = Team::factory()->create(['location' => 'Chicago', 'name' => 'Cubs']);
    $away = Team::factory()->create(['location' => 'St. Louis', 'name' => 'Cardinals']);
    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $home->id,
        'espn_id' => 'trusted-home-pitcher',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $away->id,
        'espn_id' => 'trusted-away-pitcher',
    ]);

    Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-06-13',
        'game_time' => '19:10:00',
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'home_score' => 2,
        'away_score' => 6,
    ]);

    EloRating::query()->create([
        'team_id' => $home->id,
        'season' => 2025,
        'date' => '2025-06-13',
        'elo_rating' => 1535,
    ]);
    EloRating::query()->create([
        'team_id' => $away->id,
        'season' => 2025,
        'date' => '2025-06-13',
        'elo_rating' => 1485,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $home->id,
        'season' => 2025,
        'date' => '2025-06-13',
        'elo_rating' => 1560,
        'games_started' => 8,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $awayPitcher->id,
        'team_id' => $away->id,
        'season' => 2025,
        'date' => '2025-06-13',
        'elo_rating' => 1470,
        'games_started' => 6,
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-06-15',
        'game_time' => '19:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 5,
        'away_score' => 3,
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id,
        'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
    ]);

    GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'bookmaker_key' => 'draftkings',
        'source' => 'test',
        'commence_time' => '2025-06-15 19:10:00',
        'captured_at' => '2025-06-15 12:00:00',
        'payload_hash' => hash('sha256', 'trusted-pregame'),
        'odds_data' => trustedHistoricalOddsData(
            'Chicago Cubs',
            'St. Louis Cardinals',
            -125,
            110,
            -1.5,
            8.5,
        ),
    ]);

    $this->artisan('mlb:backfill-historical-predictions', [
        '--from-date' => '2025-06-15',
        '--to-date' => '2025-06-15',
        '--profile' => TrustedHistoricalFeatureBuilder::PROFILE,
        '--limit' => 1,
    ])->assertSuccessful();

    $prediction = Prediction::query()->where('game_id', $game->id)->firstOrFail();
    $snapshot = PredictionFeatureSnapshot::query()
        ->where('game_id', $game->id)
        ->where('lineage_metadata->historical_profile', TrustedHistoricalFeatureBuilder::PROFILE)
        ->sole();
    $evaluation = PredictionEvaluation::query()
        ->where('prediction_id', $prediction->id)
        ->where('model_version', TrustedHistoricalPredictionReconstructor::MODEL_VERSION)
        ->where('feature_version', TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
        ->sole();
    $run = ModelRun::query()->findOrFail($snapshot->model_run_id);

    expect($prediction->model_version)->toBe(TrustedHistoricalPredictionReconstructor::MODEL_VERSION)
        ->and($prediction->feature_version)->toBe(TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
        ->and($snapshot->pregame_safe)->toBeTrue()
        ->and($snapshot->availability_status)->toBe('verified_reconstruction')
        ->and($snapshot->features_available_at?->lte($snapshot->game_start_at))->toBeTrue()
        ->and(data_get($snapshot->lineage_metadata, 'run_type'))->toBe('historical_reconstruction')
        ->and(data_get($snapshot->lineage_metadata, 'point_in_time_verified'))->toBeTrue()
        ->and(data_get($snapshot->features, 'home_prior_games'))->toBe(1)
        ->and(data_get($snapshot->features, 'home_team_elo'))->toBe(1535)
        ->and(data_get($snapshot->features, 'home_pitcher_elo'))->toBe(1500)
        ->and(data_get($snapshot->features, 'away_pitcher_elo'))->toBe(1500)
        ->and(data_get($snapshot->features, 'home_pitcher_known'))->toBe(0)
        ->and(data_get($snapshot->features, 'away_pitcher_known'))->toBe(0)
        ->and(data_get($snapshot->features, 'home_pitcher_confidence'))->toBe(0)
        ->and(data_get($snapshot->features, 'away_pitcher_confidence'))->toBe(0)
        ->and(data_get(
            $snapshot->model_metadata,
            'historical_reconstruction.historical_pitcher_features.reason',
        ))->toBe('timestamped_pregame_probable_pitcher_history_unavailable')
        ->and(data_get(
            $snapshot->model_metadata,
            'historical_reconstruction.historical_pitcher_features.mutable_game_probable_pitcher_ids_used',
        ))->toBeFalse()
        ->and(data_get($snapshot->features, 'market_bookmaker_home_line'))->toBe(-1.5)
        ->and(data_get($snapshot->features, 'market_home_margin'))->toBe(1.5)
        ->and(data_get($snapshot->outputs, 'market_spread'))->toBe(1.5)
        ->and(data_get($snapshot->market_context, 'snapshot_id'))->not->toBeNull()
        ->and(data_get($snapshot->model_metadata, 'target_hash'))->toHaveLength(64)
        ->and($evaluation->game_id)->toBe($game->id)
        ->and($run->run_type)->toBe('historical_reconstruction')
        ->and(data_get($run->parameters, 'historical_profile'))->toBe(TrustedHistoricalFeatureBuilder::PROFILE);

    $count = PredictionFeatureSnapshot::query()
        ->where('game_id', $game->id)
        ->where('lineage_metadata->historical_profile', TrustedHistoricalFeatureBuilder::PROFILE)
        ->count();

    $this->artisan('mlb:backfill-historical-predictions', [
        '--season' => 2025,
        '--profile' => TrustedHistoricalFeatureBuilder::PROFILE,
        '--only-missing-profile' => true,
    ])->assertSuccessful();

    expect(PredictionFeatureSnapshot::query()
        ->where('game_id', $game->id)
        ->where('lineage_metadata->historical_profile', TrustedHistoricalFeatureBuilder::PROFILE)
        ->count())->toBe($count);
});

it('never mutates an existing 2026 baseline while recording trusted snapshot and evaluation lineage', function () {
    $home = Team::factory()->create(['location' => 'Los Angeles', 'name' => 'Dodgers']);
    $away = Team::factory()->create(['location' => 'San Diego', 'name' => 'Padres']);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-07-10',
        'game_time' => '19:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 6,
        'away_score' => 4,
    ]);
    $baseline = Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => '2',
        'home_team_elo' => 1551.5,
        'away_team_elo' => 1512.5,
        'home_pitcher_elo' => 1540.0,
        'away_pitcher_elo' => 1495.0,
        'home_combined_elo' => 1548.6,
        'away_combined_elo' => 1508.1,
        'predicted_spread' => 1.8,
        'predicted_total' => 8.7,
        'win_probability' => 0.621,
        'confidence_score' => 62.1,
        'vegas_spread' => -1.5,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'model_metadata' => [
            'public_source' => 'baseline',
            'public' => true,
            'tracking_only' => false,
        ],
        'actual_spread' => 2.0,
        'actual_total' => 10.0,
        'spread_error' => 0.2,
        'total_error' => 1.3,
        'winner_correct' => true,
        'graded_at' => '2026-07-11 04:30:00',
        'narrative_json' => ['summary' => 'Public baseline narrative'],
        'narrative_provider' => 'baseline-provider',
        'narrative_model' => 'baseline-model',
        'narrative_input_hash' => hash('sha256', 'public-baseline'),
        'narrative_latency_ms' => 125,
        'narrative_generated_at' => '2026-07-10 12:00:00',
    ]);
    $before = $baseline->fresh()->getAttributes();

    Artisan::call('mlb:backfill-historical-predictions', [
        '--from-date' => '2026-07-10',
        '--to-date' => '2026-07-10',
        '--profile' => TrustedHistoricalFeatureBuilder::PROFILE,
    ]);

    $after = Prediction::query()->findOrFail($baseline->id);
    $snapshot = PredictionFeatureSnapshot::query()
        ->where('prediction_id', $baseline->id)
        ->where('model_version', TrustedHistoricalPredictionReconstructor::MODEL_VERSION)
        ->where('feature_version', TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
        ->sole();
    $evaluation = PredictionEvaluation::query()
        ->where('prediction_id', $baseline->id)
        ->where('model_version', TrustedHistoricalPredictionReconstructor::MODEL_VERSION)
        ->where('feature_version', TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
        ->sole();

    expect($after->getAttributes())->toBe($before)
        ->and(Prediction::query()->where('game_id', $game->id)->count())->toBe(1)
        ->and($after->model_version)->toBe('rules-v1')
        ->and($after->feature_version)->toBe('core-v3')
        ->and(data_get($after->model_metadata, 'public_source'))->toBe('baseline')
        ->and($snapshot->prediction_id)->toBe($baseline->id)
        ->and($snapshot->availability_status)->toBe('verified_reconstruction')
        ->and($snapshot->model_version)->toBe(TrustedHistoricalPredictionReconstructor::MODEL_VERSION)
        ->and($evaluation->blend_version)->toBe(TrustedHistoricalPredictionReconstructor::BLEND_VERSION)
        ->and((float) data_get($evaluation->errors, 'active_win_probability'))
        ->toBe((float) data_get($snapshot->outputs, 'win_probability'))
        ->and(Artisan::output())->toContain('Graded 1 prediction(s)');
});

it('keeps future results ratings and post-start odds out of trusted reconstructed features', function () {
    $home = Team::factory()->create(['location' => 'New York', 'name' => 'Yankees']);
    $away = Team::factory()->create(['location' => 'Boston', 'name' => 'Red Sox']);
    $homePitcher = Player::factory()->pitcher()->create([
        'team_id' => $home->id,
        'espn_id' => 'future-safe-home',
    ]);
    $awayPitcher = Player::factory()->pitcher()->create([
        'team_id' => $away->id,
        'espn_id' => 'future-safe-away',
    ]);

    Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-07-01',
        'game_time' => '13:05:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 4,
        'away_score' => 2,
    ]);

    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-07-02',
        'game_time' => '19:05:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 3,
        'away_score' => 1,
        'probable_home_pitcher_espn_id' => $homePitcher->espn_id,
        'probable_away_pitcher_espn_id' => $awayPitcher->espn_id,
    ]);

    EloRating::query()->create([
        'team_id' => $home->id,
        'season' => 2025,
        'date' => '2025-07-01',
        'elo_rating' => 1510,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $home->id,
        'season' => 2025,
        'date' => '2025-07-01',
        'elo_rating' => 1525,
        'games_started' => 5,
    ]);

    GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'bookmaker_key' => 'draftkings',
        'source' => 'test',
        'commence_time' => '2025-07-02 19:05:00',
        'captured_at' => '2025-07-02 12:00:00',
        'payload_hash' => hash('sha256', 'before-start'),
        'odds_data' => trustedHistoricalOddsData(
            'New York Yankees',
            'Boston Red Sox',
            -120,
            105,
            -1.5,
            8.0,
        ),
    ]);
    GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'bookmaker_key' => 'draftkings',
        'source' => 'test',
        'commence_time' => '2025-07-02 19:05:00',
        'captured_at' => '2025-07-02 19:10:00',
        'payload_hash' => hash('sha256', 'after-start'),
        'odds_data' => trustedHistoricalOddsData(
            'New York Yankees',
            'Boston Red Sox',
            -400,
            320,
            -4.5,
            12.5,
        ),
    ]);

    $reconstructor = app(TrustedHistoricalPredictionReconstructor::class);
    $reconstructor->reconstruct($game->fresh(['homeTeam', 'awayTeam']));
    $first = PredictionFeatureSnapshot::query()
        ->where('game_id', $game->id)
        ->where('feature_version', TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
        ->latest('id')
        ->firstOrFail();

    Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-07-03',
        'game_time' => '19:05:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 30,
        'away_score' => 0,
    ]);
    EloRating::query()->create([
        'team_id' => $home->id,
        'season' => 2025,
        'date' => '2025-07-03',
        'elo_rating' => 1999,
    ]);
    PitcherEloRating::query()->create([
        'player_id' => $homePitcher->id,
        'team_id' => $home->id,
        'season' => 2025,
        'date' => '2025-07-03',
        'elo_rating' => 1999,
        'games_started' => 99,
    ]);
    GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $game->id,
        'bookmaker_key' => 'draftkings',
        'source' => 'test',
        'commence_time' => '2025-07-02 19:05:00',
        'captured_at' => '2025-07-02 20:00:00',
        'payload_hash' => hash('sha256', 'later-after-start'),
        'odds_data' => trustedHistoricalOddsData(
            'New York Yankees',
            'Boston Red Sox',
            -900,
            700,
            -9.5,
            20.5,
        ),
    ]);

    $game->update([
        'probable_home_pitcher_espn_id' => 'postgame-corrected-home',
        'probable_away_pitcher_espn_id' => 'postgame-corrected-away',
    ]);

    $reconstructor->reconstruct($game->fresh(['homeTeam', 'awayTeam']));
    $second = PredictionFeatureSnapshot::query()
        ->where('game_id', $game->id)
        ->where('feature_version', TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
        ->latest('id')
        ->firstOrFail();

    expect($second->feature_hash)->toBe($first->feature_hash)
        ->and($second->features)->toBe($first->features)
        ->and(data_get($second->features, 'home_team_elo'))->toBe(1510)
        ->and(data_get($second->features, 'home_pitcher_elo'))->toBe(1500)
        ->and(data_get($second->features, 'home_pitcher_known'))->toBe(0)
        ->and(data_get($second->features, 'home_pitcher_confidence'))->toBe(0)
        ->and((float) data_get($second->features, 'home_rolling_runs_scored_20'))->toBe(4.0)
        ->and(data_get($second->features, 'market_bookmaker_home_line'))->toBe(-1.5)
        ->and((float) data_get($second->features, 'market_total'))->toBe(8.0);
});

it('keeps default research reconstruction outside standardized strict export predicates', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-08-10',
        'game_time' => '13:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 5,
        'away_score' => 2,
    ]);

    $this->artisan('mlb:backfill-historical-predictions', [
        '--season' => 2025,
        '--limit' => 1,
    ])->assertSuccessful();

    $researchSnapshot = PredictionFeatureSnapshot::query()
        ->where('game_id', $game->id)
        ->latest('id')
        ->firstOrFail();

    expect($researchSnapshot->pregame_safe)->toBeFalse()
        ->and($researchSnapshot->availability_status)->not->toBe('verified_reconstruction');

    $this->artisan('mlb:backfill-historical-predictions', [
        '--season' => 2025,
        '--profile' => TrustedHistoricalFeatureBuilder::PROFILE,
        '--limit' => 1,
    ])->assertSuccessful();

    $strictSnapshots = PredictionFeatureSnapshot::query()
        ->where('sport', 'mlb')
        ->where('prediction_table', 'mlb_predictions')
        ->where('pregame_safe', true)
        ->whereNotNull('model_run_id')
        ->whereNotNull('game_start_at')
        ->whereNotNull('features_available_at')
        ->whereColumn('features_available_at', '<=', 'game_start_at')
        ->whereIn('availability_status', ['observed_pregame', 'verified_reconstruction'])
        ->get();

    expect($strictSnapshots)->toHaveCount(1)
        ->and($strictSnapshots->sole()->id)->not->toBe($researchSnapshot->id)
        ->and(data_get($strictSnapshots->sole()->lineage_metadata, 'historical_profile'))
        ->toBe(TrustedHistoricalFeatureBuilder::PROFILE);
});

it('processes trusted historical games by scheduled start and id', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $later = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-09-01',
        'game_time' => '19:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 2,
        'away_score' => 1,
    ]);
    $earlier = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-09-01',
        'game_time' => '13:10:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 4,
        'away_score' => 3,
    ]);
    $sameStartHigherId = Game::factory()->create([
        'season' => 2025,
        'season_type' => '2',
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-09-01',
        'game_time' => '13:10:00',
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'home_score' => 1,
        'away_score' => 5,
    ]);

    $this->artisan('mlb:backfill-historical-predictions', [
        '--season' => 2025,
        '--profile' => TrustedHistoricalFeatureBuilder::PROFILE,
        '--limit' => 1,
    ])->assertSuccessful();

    expect(Prediction::query()->where('game_id', $earlier->id)->exists())->toBeTrue()
        ->and(Prediction::query()->where('game_id', $sameStartHigherId->id)->exists())->toBeFalse()
        ->and(Prediction::query()->where('game_id', $later->id)->exists())->toBeFalse();
});

it('exports mlb training data from snapshots and evaluations', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2025,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => 'STATUS_FINAL',
        'game_date' => '2025-06-15',
        'game_time' => '19:10:00',
        'home_score' => 7,
        'away_score' => 4,
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 1.5,
        'predicted_total' => 8.5,
        'win_probability' => 0.57,
        'confidence_score' => 57,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
    ]);

    $firstRunId = (string) Str::uuid();
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => $firstRunId,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => [
            'home_combined_elo' => 1512.5,
            'away_combined_elo' => 1497.8,
        ],
        'outputs' => [
            'predicted_spread' => 1.5,
            'predicted_total' => 8.5,
            'confidence_score' => 57,
        ],
        'market_context' => [
            'vegas_spread' => -1.5,
        ],
        'model_metadata' => [
            'feature_set' => 'historical-priors',
        ],
        'feature_hash' => 'mlb-test-hash',
        'generated_at' => now(),
        'game_start_at' => '2025-06-15 19:10:00',
        'features_available_at' => '2025-06-15 19:10:00',
        'pregame_safe' => true,
        'availability_status' => 'verified_reconstruction',
        'lineage_metadata' => [
            'point_in_time_verified' => true,
        ],
    ]);

    $secondRunId = (string) Str::uuid();
    PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => $secondRunId,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => [
            'home_combined_elo' => 1490.0,
            'away_combined_elo' => 1520.0,
        ],
        'outputs' => [
            'predicted_spread' => -2.0,
            'predicted_total' => 10.0,
            'win_probability' => 0.42,
            'confidence_score' => 58,
        ],
        'market_context' => [
            'vegas_spread' => -1.5,
        ],
        'feature_hash' => 'mlb-test-hash-second-run',
        'generated_at' => now()->addSecond(),
        'game_start_at' => '2025-06-15 19:10:00',
        'features_available_at' => '2025-06-15 19:10:00',
        'pregame_safe' => true,
        'availability_status' => 'verified_reconstruction',
        'lineage_metadata' => [
            'point_in_time_verified' => true,
        ],
    ]);

    PredictionEvaluation::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'actuals' => [
            'actual_spread' => 3.0,
            'actual_total' => 11.0,
        ],
        'errors' => [
            'spread_error' => 1.5,
            'total_error' => 2.5,
            'winner_correct' => true,
            'brier_score' => 0.1849,
        ],
        'market_comparison' => [
            'model_beats_market_spread' => true,
        ],
        'evaluated_at' => now(),
    ]);

    $path = storage_path('app/ml/test_mlb_training_data.csv');
    @unlink($path);

    Artisan::call('mlb:export-training-data', [
        '--path' => $path,
        '--season' => [2025],
    ]);

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)->toContain('feature_home_combined_elo')
        ->toContain('output_predicted_spread')
        ->toContain('actual_actual_spread')
        ->toContain('error_brier_score')
        ->toContain('market_model_beats_market_spread');

    $csv = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
    $headers = array_shift($csv);
    $exported = collect($csv)->map(fn (array $row): array => array_combine($headers, $row));

    expect($exported)->toHaveCount(1)
        ->and($exported->pluck('snapshot_run_id')->all())->toBe([$secondRunId])
        ->and($exported->pluck('error_spread_error')->map(fn (string $value): float => (float) $value)->all())
        ->toBe([5.0]);
});

/**
 * @return array<string, mixed>
 */
function trustedHistoricalOddsData(
    string $homeTeam,
    string $awayTeam,
    int $homeMoneyline,
    int $awayMoneyline,
    float $homeSpread,
    float $total,
): array {
    return [
        'home_team' => $homeTeam,
        'away_team' => $awayTeam,
        'bookmakers' => [[
            'key' => 'draftkings',
            'markets' => [
                [
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => $homeTeam, 'price' => $homeMoneyline],
                        ['name' => $awayTeam, 'price' => $awayMoneyline],
                    ],
                ],
                [
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => $homeTeam, 'point' => $homeSpread, 'price' => -110],
                        ['name' => $awayTeam, 'point' => -$homeSpread, 'price' => -110],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => $total, 'price' => -110],
                        ['name' => 'Under', 'point' => $total, 'price' => -110],
                    ],
                ],
            ],
        ]],
    ];
}
