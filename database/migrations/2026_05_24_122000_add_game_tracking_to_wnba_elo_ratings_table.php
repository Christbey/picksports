<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wnba_elo_ratings') || Schema::hasColumn('wnba_elo_ratings', 'game_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('wnba_elo_ratings', function (Blueprint $table): void {
                $table->dropUnique('wnba_elo_ratings_team_id_season_week_unique');
                $table->unsignedBigInteger('game_id')->nullable()->after('team_id');
                $table->date('date')->nullable()->after('season');
                $table->decimal('elo_change', 10, 1)->nullable()->after('elo_rating');
                $table->foreign('game_id')->references('id')->on('wnba_games')->cascadeOnDelete();
                $table->unique(['team_id', 'game_id']);
                $table->index(['team_id', 'season', 'date']);
                $table->index('date');
            });

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP FOREIGN KEY wnba_elo_ratings_team_id_foreign');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP INDEX wnba_elo_ratings_team_id_season_week_unique');
        DB::statement('ALTER TABLE wnba_elo_ratings MODIFY week INT NULL');
        DB::statement('ALTER TABLE wnba_elo_ratings
            ADD COLUMN game_id BIGINT UNSIGNED NULL AFTER team_id,
            ADD COLUMN date DATE NULL AFTER season,
            ADD COLUMN elo_change DECIMAL(10,1) NULL AFTER elo_rating');
        DB::statement('ALTER TABLE wnba_elo_ratings
            ADD CONSTRAINT wnba_elo_ratings_team_id_foreign
            FOREIGN KEY (team_id) REFERENCES wnba_teams(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE wnba_elo_ratings
            ADD CONSTRAINT wnba_elo_ratings_game_id_foreign
            FOREIGN KEY (game_id) REFERENCES wnba_games(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE wnba_elo_ratings
            ADD UNIQUE wnba_elo_ratings_team_id_game_id_unique (team_id, game_id)');
        DB::statement('ALTER TABLE wnba_elo_ratings
            ADD INDEX wnba_elo_ratings_team_id_season_date_index (team_id, season, date)');
        DB::statement('ALTER TABLE wnba_elo_ratings
            ADD INDEX wnba_elo_ratings_date_index (date)');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        if (! Schema::hasTable('wnba_elo_ratings') || ! Schema::hasColumn('wnba_elo_ratings', 'game_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('wnba_elo_ratings', function (Blueprint $table): void {
                $table->dropForeign(['game_id']);
                $table->dropUnique(['team_id', 'game_id']);
                $table->dropIndex(['team_id', 'season', 'date']);
                $table->dropIndex(['date']);
                $table->dropColumn(['game_id', 'date', 'elo_change']);
                $table->unique(['team_id', 'season', 'week']);
            });

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP FOREIGN KEY wnba_elo_ratings_team_id_foreign');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP FOREIGN KEY wnba_elo_ratings_game_id_foreign');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP INDEX wnba_elo_ratings_team_id_game_id_unique');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP INDEX wnba_elo_ratings_team_id_season_date_index');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP INDEX wnba_elo_ratings_date_index');
        DB::statement('ALTER TABLE wnba_elo_ratings DROP COLUMN game_id, DROP COLUMN date, DROP COLUMN elo_change');
        DB::statement('ALTER TABLE wnba_elo_ratings MODIFY week INT NOT NULL');
        DB::statement('ALTER TABLE wnba_elo_ratings ADD UNIQUE wnba_elo_ratings_team_id_season_week_unique (team_id, season, week)');
        DB::statement('ALTER TABLE wnba_elo_ratings
            ADD CONSTRAINT wnba_elo_ratings_team_id_foreign
            FOREIGN KEY (team_id) REFERENCES wnba_teams(id) ON DELETE CASCADE');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
