<?php

use App\Services\NBA\HistoricalSnapshotQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $snapshotPath = database_path('nba_snapshot_export_testing.sqlite');

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
        $table->decimal('actual_spread', 8, 1)->nullable();
        $table->decimal('actual_total', 8, 1)->nullable();
        $table->decimal('spread_error', 8, 1)->nullable();
        $table->decimal('total_error', 8, 1)->nullable();
        $table->boolean('winner_correct')->nullable();
    });
});

afterEach(function () {
    DB::disconnect('nba_snapshot');

    $snapshotPath = database_path('nba_snapshot_export_testing.sqlite');
    if (file_exists($snapshotPath)) {
        unlink($snapshotPath);
    }
});

it('exports an ml-ready dataset from the nba snapshot connection', function () {
    DB::connection('nba_snapshot')->table('nba_teams')->insert([
        ['id' => 1, 'abbreviation' => 'DEN'],
        ['id' => 2, 'abbreviation' => 'OKC'],
    ]);

    DB::connection('nba_snapshot')->table('nba_games')->insert([
        'id' => 3001,
        'espn_event_id' => '401999001',
        'season' => 2026,
        'game_date' => '2026-03-01 20:00:00',
        'home_team_id' => 1,
        'away_team_id' => 2,
        'home_score' => 118,
        'away_score' => 109,
        'status' => 'STATUS_FINAL',
    ]);

    DB::connection('nba_snapshot')->table('nba_predictions')->insert([
        'id' => 9001,
        'game_id' => 3001,
        'home_elo' => 1620.5,
        'away_elo' => 1591.0,
        'home_recent_form' => 4.200,
        'away_recent_form' => 2.100,
        'rest_days_home' => 2,
        'rest_days_away' => 1,
        'injury_spread_adj' => -0.5,
        'injury_total_adj' => 1.0,
        'vegas_spread' => 5.5,
        'predicted_spread' => 7.5,
        'predicted_total' => 227.5,
        'win_probability' => 0.682,
        'confidence_score' => 74.0,
        'actual_spread' => 9.0,
        'actual_total' => 227.0,
        'spread_error' => 1.5,
        'total_error' => 0.5,
        'winner_correct' => true,
    ]);

    $path = storage_path('app/ml/test_nba_snapshot_dataset.csv');
    @unlink($path);

    Artisan::call('nba:export-snapshot-dataset', [
        '--season' => 2026,
        '--path' => $path,
        '--include-identifiers' => true,
    ]);

    $contents = file_get_contents($path);

    expect(app(HistoricalSnapshotQueryService::class)->trainingRows(2026))->toHaveCount(1)
        ->and(file_exists($path))->toBeTrue()
        ->and($contents)->toContain('prediction_id')
        ->toContain('feature_elo_diff')
        ->toContain('feature_market_home_spread')
        ->toContain('target_home_margin')
        ->toContain('target_model_spread_error')
        ->toContain('DEN')
        ->toContain('OKC')
        ->toContain('401999001')
        ->toContain('29.5')
        ->toContain('2');
});
