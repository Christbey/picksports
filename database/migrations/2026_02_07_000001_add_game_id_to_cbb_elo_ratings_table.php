<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Use raw SQL to avoid Schema builder constraint validation
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // First, drop the foreign key on team_id (which may be using the unique index)
        DB::statement('ALTER TABLE cbb_elo_ratings DROP FOREIGN KEY cbb_elo_ratings_team_id_foreign');

        // Now we can drop the unique constraint
        DB::statement('ALTER TABLE cbb_elo_ratings DROP INDEX cbb_elo_ratings_team_id_season_week_unique');

        // Make week nullable
        DB::statement('ALTER TABLE cbb_elo_ratings MODIFY week INT NULL');

        // Add new columns
        DB::statement('ALTER TABLE cbb_elo_ratings
            ADD COLUMN game_id BIGINT UNSIGNED NULL AFTER team_id,
            ADD COLUMN date DATE NULL AFTER season,
            ADD COLUMN elo_change DECIMAL(10,1) NULL AFTER elo_rating');

        // Recreate the foreign key on team_id
        DB::statement('ALTER TABLE cbb_elo_ratings
            ADD CONSTRAINT cbb_elo_ratings_team_id_foreign
            FOREIGN KEY (team_id) REFERENCES cbb_teams(id) ON DELETE CASCADE');

        // Add foreign key on game_id
        DB::statement('ALTER TABLE cbb_elo_ratings
            ADD CONSTRAINT cbb_elo_ratings_game_id_foreign
            FOREIGN KEY (game_id) REFERENCES cbb_games(id) ON DELETE CASCADE');

        // Add new unique constraint
        DB::statement('ALTER TABLE cbb_elo_ratings
            ADD UNIQUE cbb_elo_ratings_team_id_game_id_unique (team_id, game_id)');

        // Add indexes
        DB::statement('ALTER TABLE cbb_elo_ratings
            ADD INDEX cbb_elo_ratings_team_id_season_date_index (team_id, season, date)');
        DB::statement('ALTER TABLE cbb_elo_ratings
            ADD INDEX cbb_elo_ratings_date_index (date)');

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Use raw SQL for rollback as well
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Drop foreign keys
        DB::statement('ALTER TABLE cbb_elo_ratings DROP FOREIGN KEY cbb_elo_ratings_team_id_foreign');
        DB::statement('ALTER TABLE cbb_elo_ratings DROP FOREIGN KEY cbb_elo_ratings_game_id_foreign');

        // Drop unique constraint
        DB::statement('ALTER TABLE cbb_elo_ratings DROP INDEX cbb_elo_ratings_team_id_game_id_unique');

        // Drop indexes
        DB::statement('ALTER TABLE cbb_elo_ratings DROP INDEX cbb_elo_ratings_team_id_season_date_index');
        DB::statement('ALTER TABLE cbb_elo_ratings DROP INDEX cbb_elo_ratings_date_index');

        // Drop columns
        DB::statement('ALTER TABLE cbb_elo_ratings DROP COLUMN game_id, DROP COLUMN date, DROP COLUMN elo_change');

        // Make week NOT NULL again
        DB::statement('ALTER TABLE cbb_elo_ratings MODIFY week INT NOT NULL');

        // Recreate original unique constraint
        DB::statement('ALTER TABLE cbb_elo_ratings ADD UNIQUE cbb_elo_ratings_team_id_season_week_unique (team_id, season, week)');

        // Recreate the original foreign key on team_id
        DB::statement('ALTER TABLE cbb_elo_ratings
            ADD CONSTRAINT cbb_elo_ratings_team_id_foreign
            FOREIGN KEY (team_id) REFERENCES cbb_teams(id) ON DELETE CASCADE');

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
