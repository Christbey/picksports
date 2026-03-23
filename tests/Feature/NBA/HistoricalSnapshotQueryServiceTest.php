<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Services\NBA\HistoricalSnapshotQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $snapshotPath = database_path('nba_snapshot_testing.sqlite');

    if (file_exists($snapshotPath)) {
        unlink($snapshotPath);
    }

    touch($snapshotPath);

    Config::set('database.connections.nba_snapshot', [
        'driver' => 'sqlite',
        'database' => $snapshotPath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('nba_snapshot');

    Schema::connection('nba_snapshot')->create('nba_teams', function (Blueprint $table): void {
        $table->id();
        $table->string('abbreviation');
    });

    Schema::connection('nba_snapshot')->create('nba_games', function (Blueprint $table): void {
        $table->id();
        $table->string('espn_event_id')->nullable();
        $table->unsignedInteger('season');
        $table->dateTime('game_date')->nullable();
        $table->unsignedBigInteger('home_team_id');
        $table->unsignedBigInteger('away_team_id');
        $table->integer('home_score')->nullable();
        $table->integer('away_score')->nullable();
        $table->string('status')->nullable();
    });

    Schema::connection('nba_snapshot')->create('nba_predictions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('game_id');
        $table->decimal('home_elo', 8, 1)->nullable();
        $table->decimal('away_elo', 8, 1)->nullable();
        $table->decimal('home_recent_form', 8, 3)->nullable();
        $table->decimal('away_recent_form', 8, 3)->nullable();
        $table->integer('rest_days_home')->nullable();
        $table->integer('rest_days_away')->nullable();
        $table->decimal('injury_spread_adj', 8, 2)->nullable();
        $table->decimal('injury_total_adj', 8, 2)->nullable();
        $table->decimal('vegas_spread', 8, 2)->nullable();
        $table->decimal('predicted_spread', 8, 1)->nullable();
        $table->decimal('predicted_total', 8, 1)->nullable();
        $table->decimal('win_probability', 8, 3)->nullable();
        $table->decimal('confidence_score', 8, 2)->nullable();
        $table->string('model_version')->nullable();
        $table->string('feature_version')->nullable();
        $table->string('blend_version')->nullable();
        $table->decimal('actual_spread', 8, 1)->nullable();
        $table->decimal('actual_total', 8, 1)->nullable();
        $table->decimal('spread_error', 8, 1)->nullable();
        $table->decimal('total_error', 8, 1)->nullable();
        $table->boolean('winner_correct')->nullable();
    });
});

afterEach(function () {
    DB::disconnect('nba_snapshot');

    $snapshotPath = database_path('nba_snapshot_testing.sqlite');
    if (file_exists($snapshotPath)) {
        unlink($snapshotPath);
    }
});

it('loads training rows and summaries from the nba snapshot connection', function () {
    DB::connection('nba_snapshot')->table('nba_teams')->insert([
        ['id' => 1, 'abbreviation' => 'CHI'],
        ['id' => 2, 'abbreviation' => 'NYK'],
        ['id' => 3, 'abbreviation' => 'LAL'],
        ['id' => 4, 'abbreviation' => 'BOS'],
    ]);

    DB::connection('nba_snapshot')->table('nba_games')->insert([
        [
            'id' => 101,
            'espn_event_id' => '401000101',
            'season' => 2026,
            'game_date' => '2026-02-01 19:00:00',
            'home_team_id' => 1,
            'away_team_id' => 2,
            'home_score' => 112,
            'away_score' => 104,
            'status' => 'STATUS_FINAL',
        ],
        [
            'id' => 102,
            'espn_event_id' => '401000102',
            'season' => 2025,
            'game_date' => '2025-12-15 19:00:00',
            'home_team_id' => 3,
            'away_team_id' => 4,
            'home_score' => 99,
            'away_score' => 103,
            'status' => 'STATUS_FINAL',
        ],
    ]);

    DB::connection('nba_snapshot')->table('nba_predictions')->insert([
        [
            'id' => 501,
            'game_id' => 101,
            'home_elo' => 1542.1,
            'away_elo' => 1497.4,
            'home_recent_form' => 3.245,
            'away_recent_form' => -1.300,
            'rest_days_home' => 2,
            'rest_days_away' => 1,
            'injury_spread_adj' => -0.5,
            'injury_total_adj' => 1.0,
            'vegas_spread' => 4.5,
            'predicted_spread' => 6.5,
            'predicted_total' => 219.5,
            'win_probability' => 0.641,
            'confidence_score' => 72.5,
            'model_version' => 'rules-v1',
            'feature_version' => 'core-v1',
            'blend_version' => 'baseline-v1',
            'actual_spread' => 8.0,
            'actual_total' => 216.0,
            'spread_error' => 1.5,
            'total_error' => 3.5,
            'winner_correct' => true,
        ],
        [
            'id' => 502,
            'game_id' => 102,
            'home_elo' => 1500.0,
            'away_elo' => 1510.0,
            'home_recent_form' => 1.100,
            'away_recent_form' => 2.000,
            'rest_days_home' => 1,
            'rest_days_away' => 2,
            'injury_spread_adj' => 0.0,
            'injury_total_adj' => 0.5,
            'vegas_spread' => -2.5,
            'predicted_spread' => -1.5,
            'predicted_total' => 214.0,
            'win_probability' => 0.470,
            'confidence_score' => 61.0,
            'model_version' => 'rules-v1',
            'feature_version' => 'core-v1',
            'blend_version' => 'baseline-v1',
            'actual_spread' => -4.0,
            'actual_total' => 202.0,
            'spread_error' => 2.5,
            'total_error' => 12.0,
            'winner_correct' => true,
        ],
    ]);

    $liveHome = Team::factory()->create(['abbreviation' => 'MIA']);
    $liveAway = Team::factory()->create(['abbreviation' => 'ATL']);
    $liveGame = Game::factory()->create([
        'home_team_id' => $liveHome->id,
        'away_team_id' => $liveAway->id,
        'season' => 2026,
        'status' => 'STATUS_FINAL',
        'home_score' => 140,
        'away_score' => 80,
    ]);
    Prediction::query()->create([
        'game_id' => $liveGame->id,
        'predicted_spread' => 60.0,
        'predicted_total' => 220.0,
        'win_probability' => 0.99,
        'confidence_score' => 99.0,
        'model_version' => 'live-only',
        'feature_version' => 'live-only',
        'blend_version' => 'live-only',
    ]);

    $service = app(HistoricalSnapshotQueryService::class);

    $rows = $service->trainingRows(2026);
    $summary = $service->datasetSummary(2026);
    $seasons = $service->availableSeasons();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['prediction_id'])->toBe(501)
        ->and($rows->first()['home_team_abbreviation'])->toBe('CHI')
        ->and($rows->first()['away_team_abbreviation'])->toBe('NYK')
        ->and($rows->first()['derived_actual_spread'])->toBe(8.0)
        ->and($rows->first()['derived_actual_total'])->toBe(216.0)
        ->and($summary)->toMatchArray([
            'season' => 2026,
            'row_count' => 1,
            'graded_prediction_count' => 1,
            'first_game_date' => '2026-02-01 19:00:00',
            'last_game_date' => '2026-02-01 19:00:00',
            'avg_spread_error' => 1.5,
        ])
        ->and($seasons->all())->toBe([2026, 2025]);
});
