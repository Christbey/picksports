<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->string('placeholder_key')->default('')->after('snapshot_id');
            $table->string('team_display_name')->nullable()->after('seed');
            $table->string('team_abbreviation', 16)->nullable()->after('team_display_name');
        });

        $this->replaceSnapshotUnique();
        $this->makeTeamIdNullable();
    }

    public function down(): void
    {
        $this->restoreSnapshotUnique();
        $this->makeTeamIdRequired();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->dropColumn([
                'placeholder_key',
                'team_display_name',
                'team_abbreviation',
            ]);
        });
    }

    private function replaceSnapshotUnique(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM cbb_tournament_forecasts'))
            ->pluck('Key_name')
            ->unique()
            ->flip();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) use ($indexes) {
            if ($indexes->has('cbb_tf_snapshot_team_uniq')) {
                $table->dropUnique('cbb_tf_snapshot_team_uniq');
            }
        });

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->unique(
                ['snapshot_id', 'team_id', 'placeholder_key'],
                'cbb_tf_snapshot_team_placeholder_uniq'
            );
        });
    }

    private function restoreSnapshotUnique(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM cbb_tournament_forecasts'))
            ->pluck('Key_name')
            ->unique()
            ->flip();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) use ($indexes) {
            if ($indexes->has('cbb_tf_snapshot_team_placeholder_uniq')) {
                $table->dropUnique('cbb_tf_snapshot_team_placeholder_uniq');
            }
        });

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->unique(['snapshot_id', 'team_id'], 'cbb_tf_snapshot_team_uniq');
        });
    }

    private function makeTeamIdNullable(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE cbb_tournament_forecasts DROP FOREIGN KEY cbb_tournament_forecasts_team_id_foreign');
            DB::statement('ALTER TABLE cbb_tournament_forecasts MODIFY team_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE cbb_tournament_forecasts ADD CONSTRAINT cbb_tournament_forecasts_team_id_foreign FOREIGN KEY (team_id) REFERENCES cbb_teams(id) ON DELETE CASCADE');

            return;
        }

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->change();
        });
    }

    private function makeTeamIdRequired(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('DELETE FROM cbb_tournament_forecasts WHERE team_id IS NULL');
            DB::statement('ALTER TABLE cbb_tournament_forecasts DROP FOREIGN KEY cbb_tournament_forecasts_team_id_foreign');
            DB::statement('ALTER TABLE cbb_tournament_forecasts MODIFY team_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE cbb_tournament_forecasts ADD CONSTRAINT cbb_tournament_forecasts_team_id_foreign FOREIGN KEY (team_id) REFERENCES cbb_teams(id) ON DELETE CASCADE');

            return;
        }

        DB::table('cbb_tournament_forecasts')->whereNull('team_id')->delete();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable(false)->change();
        });
    }
};
