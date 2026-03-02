<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mlb_playoff_forecasts')) {
            Schema::create('mlb_playoff_forecasts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('mlb_teams')->onDelete('cascade');
                $table->integer('season');
                $table->string('league', 50)->nullable();
                $table->unsignedTinyInteger('league_rank')->nullable();
                $table->unsignedTinyInteger('projected_seed')->nullable();
                $table->decimal('selection_score', 7, 4)->nullable();
                $table->decimal('playoff_make_probability', 6, 5)->default(0);
                $table->decimal('league_championship_probability', 6, 5)->default(0);
                $table->decimal('world_series_probability', 6, 5)->default(0);
                $table->decimal('champion_probability', 6, 5)->default(0);
                $table->unsignedInteger('simulation_runs')->default(0);
                $table->timestamps();
            });
        }

        $this->ensureIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_playoff_forecasts');
    }

    private function ensureIndexes(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('mlb_playoff_forecasts')"))
                ->pluck('name')
                ->flip();
        } else {
            $indexes = collect(DB::select('SHOW INDEX FROM mlb_playoff_forecasts'))
                ->pluck('Key_name')
                ->flip();
        }

        Schema::table('mlb_playoff_forecasts', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->has('mlb_pf_team_season_uniq')) {
                $table->unique(['team_id', 'season'], 'mlb_pf_team_season_uniq');
            }
            if (! $indexes->has('mlb_pf_season_champ_idx')) {
                $table->index(['season', 'champion_probability'], 'mlb_pf_season_champ_idx');
            }
            if (! $indexes->has('mlb_pf_season_make_idx')) {
                $table->index(['season', 'playoff_make_probability'], 'mlb_pf_season_make_idx');
            }
        });
    }
};

