<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original game/date migration was MySQL-only. Give every driver the same schema.
        if (! Schema::hasColumn('cfb_elo_ratings', 'game_id')) {
            Schema::table('cfb_elo_ratings', function (Blueprint $table) {
                $table->unsignedBigInteger('game_id')->nullable();
                $table->date('date')->nullable();
                $table->dropUnique('cfb_elo_ratings_team_id_season_week_season_type_unique');
            });
        }
        Schema::table('cfb_elo_ratings', function (Blueprint $table) {
            // Preserve an index supporting MySQL's team foreign key when replacing its unique index.
            $table->index('team_id', 'cfb_elo_team_integrity_index');
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->decimal('elo_before', 10, 2)->nullable();
            $table->string('model_version', 40)->nullable();
            $table->string('result_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('season_initialization_id')->nullable();
            $table->timestamp('rebuilt_at')->nullable();
        });
        if (Schema::hasIndex('cfb_elo_ratings', 'cfb_elo_ratings_team_id_game_id_unique')) {
            Schema::table('cfb_elo_ratings', fn (Blueprint $table) => $table->dropUnique('cfb_elo_ratings_team_id_game_id_unique'));
        }
        Schema::table('cfb_elo_ratings', fn (Blueprint $table) => $table->unique(['team_id', 'game_id', 'active_slot'], 'cfb_elo_active_pair_unique'));
        Schema::create('cfb_elo_season_initializations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->integer('season');
            $table->string('model_version', 40);
            $table->decimal('prior_rating', 10, 2);
            $table->decimal('initial_rating', 10, 2);
            $table->decimal('regression_factor', 5, 4);
            $table->unsignedBigInteger('source_rating_id')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['team_id', 'season', 'active_slot'], 'cfb_elo_active_initialization_unique');
        });
        Schema::create('cfb_elo_rebuilds', function (Blueprint $table) {
            $table->id();
            $table->string('model_version', 40);
            $table->string('status', 30);
            $table->string('input_digest', 64);
            $table->json('payload');
            $table->json('comparison');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // A rollback must not silently discard preserved historical versions.
        throw new RuntimeException('CFB Elo integrity migration is forward-only; retained rating versions must be preserved.');
    }
};
