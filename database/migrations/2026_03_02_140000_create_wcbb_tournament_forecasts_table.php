<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wcbb_tournament_forecasts')) {
            Schema::create('wcbb_tournament_forecasts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('wcbb_teams')->onDelete('cascade');
                $table->integer('season');
                $table->decimal('selection_score', 7, 4)->nullable();
                $table->tinyInteger('projected_seed')->nullable();
                $table->boolean('auto_bid')->default(false);
                $table->decimal('auto_bid_probability', 6, 5)->default(0);
                $table->decimal('at_large_probability', 6, 5)->default(0);
                $table->decimal('first_four_probability', 6, 5)->default(0);
                $table->decimal('first_four_auto_probability', 6, 5)->default(0);
                $table->decimal('first_four_at_large_probability', 6, 5)->default(0);
                $table->decimal('bid_thief_probability', 6, 5)->default(0);
                $table->decimal('tournament_make_probability', 6, 5)->default(0);
                $table->decimal('champion_probability', 6, 5)->default(0);
                $table->decimal('final_four_probability', 6, 5)->default(0);
                $table->decimal('title_game_probability', 6, 5)->default(0);
                $table->unsignedInteger('simulated_field_appearances')->default(0);
                $table->unsignedInteger('simulated_titles')->default(0);
                $table->unsignedInteger('simulation_runs')->default(0);
                $table->timestamps();
            });
        }

        $this->ensureIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('wcbb_tournament_forecasts');
    }

    private function ensureIndexes(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('wcbb_tournament_forecasts')"))
                ->pluck('name')
                ->flip();
        } else {
            $indexes = collect(DB::select('SHOW INDEX FROM wcbb_tournament_forecasts'))
                ->pluck('Key_name')
                ->flip();
        }

        Schema::table('wcbb_tournament_forecasts', function (Blueprint $table) use ($indexes) {
            if (! $indexes->has('wcbb_tf_team_season_uniq')) {
                $table->unique(['team_id', 'season'], 'wcbb_tf_team_season_uniq');
            }

            if (! $indexes->has('wcbb_tf_season_champ_idx')) {
                $table->index(['season', 'champion_probability'], 'wcbb_tf_season_champ_idx');
            }

            if (! $indexes->has('wcbb_tf_season_make_idx')) {
                $table->index(['season', 'tournament_make_probability'], 'wcbb_tf_season_make_idx');
            }
        });
    }
};

