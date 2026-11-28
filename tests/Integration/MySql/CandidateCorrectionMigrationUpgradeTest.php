<?php

use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Player;
use App\Models\NFL\Team;
use App\Models\User;
use App\Models\WNBA\Team as WnbaTeam;
use App\Services\Predictions\ModelRunRecorder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('upgrades the measured production history without manufacturing audits or rewriting existing grades', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    Http::preventStrayRequests();
    Notification::fake();
    $manifest = json_decode(file_get_contents(base_path('docs/production-readiness/restored-production-migrations-2026-11-25.json')), true, flags: JSON_THROW_ON_ERROR);
    $directory = sys_get_temp_dir().'/picksports-correction-upgrade-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        foreach ($manifest['production_migration_names'] as $name) {
            copy(database_path('migrations/'.$name.'.php'), $directory.'/'.$name.'.php');
        }
        $this->artisan('migrate:fresh', ['--path' => $directory, '--realpath' => true, '--force' => true])->assertSuccessful();
        $baseline = DB::table('migrations')->count();
        expect($baseline)->toBe(count($manifest['production_migration_names']));
        $game = NbaGame::factory()->create(['home_team_id' => NbaTeam::factory(), 'away_team_id' => NbaTeam::factory()]);
        $prediction = Prediction::create(['game_id' => $game->id, 'predicted_spread' => 5.5, 'predicted_total' => 160,
            'win_probability' => 0.65, 'confidence_score' => 65, 'actual_spread' => 8, 'actual_total' => 160,
            'winner_correct' => true, 'graded_at' => now()->subDay()]);
        $prop = PlayerProp::create(['game_id' => $game->id, 'player_name' => 'Historical fixture',
            'market' => 'player_points', 'line' => 19.5, 'over_price' => -110, 'actual_value' => 20,
            'hit_over' => true, 'hit_under' => false, 'graded_at' => now()->subDay()]);
        $cfbGame = CfbGame::factory()->create(['home_team_id' => CfbTeam::factory(), 'away_team_id' => CfbTeam::factory()]);
        $checkId = DB::table('cfb_game_data_checks')->insertGetId(['game_id' => $cfbGame->id,
            'component' => 'boxscore', 'source_hash' => str_repeat('a', 64), 'validator_version' => 'historical-fixture',
            'state' => 'complete', 'discrepancies' => '[]', 'evidence' => '{"historical":true}',
            'source_path' => 'fixture/archive.json', 'accepted_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $trainingCycle = app(ModelRunRecorder::class)->create(sport: 'nfl', runType: 'weekly_training_cycle',
            modelVersion: 'historical-fixture', featureVersion: 'historical-fixture', blendVersion: 'historical-fixture',
            metadata: ['stage' => 'historical_training_failure', 'retained' => true], status: 'failed', completedAt: now()->subDay());
        expect(Schema::hasColumn('wnba_games', 'neutral_site'))->toBeFalse();
        $wnbaId = DB::table('wnba_games')->insertGetId(['espn_event_id' => 'historical-neutral-unknown',
            'season' => 2025, 'week' => 1, 'season_type' => 2, 'game_date' => '2025-06-01', 'game_time' => '12:00:00',
            'home_team_id' => WnbaTeam::factory()->create()->id, 'away_team_id' => WnbaTeam::factory()->create()->id,
            'status' => 'STATUS_FINAL', 'home_score' => 90, 'away_score' => 80, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        $tokenId = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => User::class,
            'tokenable_id' => $user->id, 'name' => 'Historical upgrade fixture', 'token' => str_repeat('d', 64),
            'abilities' => '["sports:read"]', 'expires_at' => '2025-11-02 01:30:00', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $sessionId = DB::table('device_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'access_token_id' => $tokenId,
            'token_family_id' => (string) Str::ulid(), 'device_name' => 'Historical native fixture',
            'platform' => 'ios', 'abilities' => '["mobile:read","mobile:write"]',
            'access_token_expires_at' => '2025-11-02 01:30:00', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $refreshId = DB::table('device_session_refresh_tokens')->insertGetId([
            'device_session_id' => $sessionId, 'token_hash' => str_repeat('e', 64),
            'expires_at' => '2025-11-02 01:45:00', 'used_at' => '2025-11-02 01:20:00',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $roleId = DB::table('roles')->insertGetId(['name' => 'historical-unclassified-upgrade', 'guard_name' => 'web']);
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $user->id]);
        $roleAssignments = DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', User::class)->get()->map(fn ($row) => (array) $row)->all();
        $subscriptionId = DB::table('subscriptions')->insertGetId(['user_id' => $user->id,
            'stripe_id' => 'sub_legacy_upgrade_fixture', 'type' => 'default', 'stripe_status' => 'active',
            'stripe_price' => 'price_historical_fixture', 'quantity' => 1, 'trial_ends_at' => '2025-11-02 01:30:00',
            'ends_at' => '2025-11-02 01:45:00', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $addedNullable = ['wnba_games' => ['neutral_site'],
            'mlb_games' => ['start_time_version', 'start_observation_id', 'start_epoch', 'start_source_hash', 'start_context_hash', 'start_observed_at'],
            'device_sessions' => ['native_policy_version', 'access_expires_epoch'],
            'device_session_refresh_tokens' => ['native_policy_version', 'expires_epoch'],
            'personal_access_tokens' => ['api_policy_version', 'api_token_profile', 'api_expires_epoch'],
            'subscriptions' => ['billing_trial_end_epoch', 'billing_end_epoch', 'billing_current_period_end_epoch',
                'billing_created_epoch', 'billing_cancel_at_period_end', 'billing_projection_attempt_id']];
        $nflTeam = Team::factory()->create(['espn_id' => '987']);
        $nflPlayer = Player::factory()->create(['team_id' => $nflTeam->id]);
        $nflAway = Team::factory()->create();
        $nflGameId = DB::table('nfl_games')->insertGetId(['espn_event_id' => 'historical-nfl-clock-upgrade',
            'season' => 2025, 'week' => 1, 'season_type' => '2', 'game_date' => '2025-11-02', 'game_time' => '01:30:00',
            'home_team_id' => $nflTeam->id, 'away_team_id' => $nflAway->id, 'status' => 'STATUS_FINAL',
            'home_score' => 21, 'away_score' => 20, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $mlbHome = MlbTeam::factory()->create();
        $mlbAway = MlbTeam::factory()->create();
        $mlbGameId = DB::table('mlb_games')->insertGetId(['espn_event_id' => 'historical-mlb-unverified-upgrade',
            'season' => 2025, 'week' => 1, 'season_type' => '2', 'game_date' => '2025-11-02', 'game_time' => '01:30:00',
            'home_team_id' => $mlbHome->id, 'away_team_id' => $mlbAway->id, 'status' => 'STATUS_FINAL',
            'home_score' => 4, 'away_score' => 3, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $nflClockColumn = fn () => (array) DB::selectOne("SELECT COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT, EXTRA, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nfl_games' AND COLUMN_NAME = 'game_time'");
        $oldClockColumn = $nflClockColumn();
        $oldInjury = DB::table('nfl_player_injuries')->insertGetId(['team_id' => $nflTeam->id, 'player_id' => $nflPlayer->id,
            'injury_key' => 'historical-fixture', 'status' => 'Out', 'is_active' => true, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $oldDepth = DB::table('nfl_depth_chart_entries')->insertGetId(['team_id' => $nflTeam->id, 'season' => 2025,
            'espn_athlete_id' => (string) $nflPlayer->espn_id, 'position_slot_key' => 'qb', 'depth_rank' => 1, 'is_starter' => true,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $oldInjurySnapshot = DB::table('nfl_player_injury_snapshots')->insertGetId(['snapshot_uuid' => (string) Str::uuid(),
            'team_id' => $nflTeam->id, 'espn_team_id' => '987', 'provider' => 'espn', 'observed_at' => now()->subDay(),
            'payload_hash' => hash('sha256', '[]'), 'entry_count' => 0, 'raw_payload' => '[]', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $oldDepthSnapshot = DB::table('nfl_depth_chart_snapshots')->insertGetId(['snapshot_uuid' => (string) Str::uuid(),
            'team_id' => $nflTeam->id, 'espn_team_id' => '987', 'season' => 2025, 'provider' => 'espn', 'observed_at' => now()->subDay(),
            'payload_hash' => hash('sha256', '[]'), 'entry_count' => 0, 'raw_payload' => '[]', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $identities = ['mlb_games' => $mlbGameId, 'nfl_games' => $nflGameId, 'nfl_player_injuries' => $oldInjury, 'nfl_depth_chart_entries' => $oldDepth,
            'nfl_player_injury_snapshots' => $oldInjurySnapshot, 'nfl_depth_chart_snapshots' => $oldDepthSnapshot, 'device_sessions' => $sessionId, 'device_session_refresh_tokens' => $refreshId, 'personal_access_tokens' => $tokenId, 'subscriptions' => $subscriptionId, 'wnba_games' => $wnbaId, 'model_runs' => $trainingCycle->id, 'nba_predictions' => $prediction->id, 'nba_player_props' => $prop->id, 'cfb_game_data_checks' => $checkId];
        $before = [];
        foreach ($identities as $table => $id) {
            $before[$table] = (array) DB::table($table)->find($id);
        }
        $submissionIds = [];
        foreach ([str_repeat('c', 255), null] as $context) {
            $submissionIds[] = DB::table('submissions')->insertGetId(['message' => 'Synthetic upgrade context', 'page_url' => $context]);
        }
        $submissionRows = fn () => DB::table('submissions')->whereIn('id', $submissionIds)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $oldSubmissionRows = $submissionRows();
        $submissionColumn = fn () => (array) DB::connection()->selectOne("SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT, COLUMN_COMMENT, IS_NULLABLE, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submissions' AND COLUMN_NAME='page_url'", [], false);
        $oldSubmissionColumn = $submissionColumn();
        expect($oldSubmissionColumn['COLUMN_TYPE'])->toBe('varchar(255)');
        $candidate = [...glob(database_path('migrations/2026_11_25_*.php')), ...glob(database_path('migrations/2026_11_26_*.php'))];
        sort($candidate);
        expect($candidate)->toHaveCount(24);
        $this->artisan('migrate', ['--path' => $candidate, '--realpath' => true, '--force' => true])->assertSuccessful();
        foreach ($before as $table => $row) {
            $after = (array) DB::table($table)->find($identities[$table]);
            foreach ($addedNullable[$table] ?? [] as $column) {
                expect($after)->toHaveKey($column)->and($after[$column])->toBeNull();
                unset($after[$column]);
            }
            if ($table === 'nba_predictions' && $row['win_probability'] !== null) {
                expect(BigDecimal::of($after['win_probability'])->isEqualTo(BigDecimal::of($row['win_probability'])))->toBeTrue();
                // Only declared DECIMAL scale representation changes; all other raw fields remain exact.
                $after['win_probability'] = $row['win_probability'];
            }
            expect($after)->toBe($row);
        }
        $newSubmissionColumn = $submissionColumn();
        expect($newSubmissionColumn['COLUMN_TYPE'])->toBe('varchar(2048)')
            ->and(array_diff_key($newSubmissionColumn, ['COLUMN_TYPE' => true]))->toBe(array_diff_key($oldSubmissionColumn, ['COLUMN_TYPE' => true]))
            ->and($submissionRows())->toBe($oldSubmissionRows);
        $newClockColumn = $nflClockColumn();
        expect($newClockColumn['IS_NULLABLE'])->toBe('YES')
            ->and(array_diff_key($newClockColumn, ['IS_NULLABLE' => true]))->toBe(array_diff_key($oldClockColumn, ['IS_NULLABLE' => true]));
        $mlbColumns = DB::select("SELECT COLUMN_NAME AS name, DATA_TYPE AS type, DATETIME_PRECISION AS precision_value, IS_NULLABLE AS nullable_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mlb_games' AND COLUMN_NAME IN ('start_time_version', 'start_observation_id', 'start_epoch', 'start_source_hash', 'start_context_hash', 'start_observed_at')");
        expect($mlbColumns)->toHaveCount(6);
        foreach ($mlbColumns as $column) {
            expect($column->nullable_value)->toBe('YES');
            if ($column->name === 'start_observed_at') {
                expect($column->type)->toBe('datetime')->and((int) $column->precision_value)->toBe(6);
            }
        }
        expect(Schema::hasColumn('mlb_games', 'start_context'))->toBeFalse();
        $auditTables = ['calculation_run_attempts', 'prediction_publication_effects', 'legacy_prediction_grade_revisions',
            'player_prop_grade_revisions', 'cfb_source_assessment_revisions', 'model_training_attempts',
            'model_training_workers', 'model_training_transfer_slots', 'model_training_uploads',
            'model_training_worker_health', 'model_training_progress', 'billing_customer_reconciliations',
            'billing_webhook_receipts', 'billing_reconciliation_attempts', 'sport_elo_season_initializations',
            'sport_elo_applications', 'sport_elo_application_effects', 'sport_elo_entity_heads', 'prediction_postgame_enrichments',
            'billing_mutation_intents', 'billing_mutation_states', 'billing_mutation_receipts',
            'billing_role_entitlements', 'billing_role_entitlement_events', 'provider_refresh_heads', 'provider_refresh_attempts'];
        foreach ($auditTables as $table) {
            expect(Schema::hasTable($table))->toBeTrue()->and(DB::table($table)->count())->toBe(0);
        }
        expect(DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', User::class)->get()->map(fn ($row) => (array) $row)->all())->toBe($roleAssignments);
        $refreshExpiry = DB::selectOne('SELECT DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['device_session_refresh_tokens', 'expires_at']);
        expect($refreshExpiry->data_type)->toBe('timestamp')->and($refreshExpiry->is_nullable)->toBe('YES');
        expect(DB::table('model_training_transfer_budgets')->pluck('id')->all())->toBe([1]);
        expect(DB::table('sport_elo_writer_gates')->orderBy('sport')->pluck('sport')->all())->toBe(['cbb', 'mlb', 'nba', 'nfl', 'wcbb']);
        expect(DB::table('sport_elo_writer_gates')->pluck('mode')->unique()->all())->toBe(['disabled']);
        expect(DB::table('sport_elo_writer_gates')->whereNotNull('calculation_version')->count())->toBe(0);
        expect(DB::table('migrations')->count())->toBe($baseline + count($candidate));
        expect($submissionRows())->toBe($oldSubmissionRows);
        $this->artisan('migrate', ['--path' => $candidate, '--realpath' => true, '--force' => true])->assertSuccessful();
        expect(DB::table('migrations')->count())->toBe($baseline + count($candidate));
        expect($submissionRows())->toBe($oldSubmissionRows);
        foreach ($before as $table => $row) {
            $after = (array) DB::table($table)->find($identities[$table]);
            foreach ($addedNullable[$table] ?? [] as $column) {
                expect($after)->toHaveKey($column)->and($after[$column])->toBeNull();
                unset($after[$column]);
            }
            if ($table === 'nba_predictions' && $row['win_probability'] !== null) {
                expect(BigDecimal::of($after['win_probability'])->isEqualTo(BigDecimal::of($row['win_probability'])))->toBeTrue();
                // Only declared DECIMAL scale representation changes; all other raw fields remain exact.
                $after['win_probability'] = $row['win_probability'];
            }
            expect($after)->toBe($row);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});
