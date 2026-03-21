<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nba_playoff_forecasts')) {
            Schema::create('nba_playoff_forecasts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('nba_teams')->onDelete('cascade');
                $table->integer('season');
                $table->string('conference', 50)->nullable();
                $table->unsignedTinyInteger('conference_rank')->nullable();
                $table->unsignedTinyInteger('projected_seed')->nullable();
                $table->decimal('selection_score', 7, 4)->nullable();
                $table->decimal('playoff_make_probability', 6, 5)->default(0);
                $table->decimal('conference_finals_probability', 6, 5)->default(0);
                $table->decimal('nba_finals_probability', 6, 5)->default(0);
                $table->decimal('champion_probability', 6, 5)->default(0);
                $table->unsignedInteger('simulation_runs')->default(0);
                $table->timestamps();
            });
        }

        $this->ensureIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('nba_playoff_forecasts');
    }

    private function ensureIndexes(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('nba_playoff_forecasts')"))
                ->pluck('name')
                ->flip();
        } else {
            $indexes = collect(DB::select('SHOW INDEX FROM nba_playoff_forecasts'))
                ->pluck('Key_name')
                ->flip();
        }

        Schema::table('nba_playoff_forecasts', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->has('nba_pf_team_season_uniq')) {
                $table->unique(['team_id', 'season'], 'nba_pf_team_season_uniq');
            }
            if (! $indexes->has('nba_pf_season_champ_idx')) {
                $table->index(['season', 'champion_probability'], 'nba_pf_season_champ_idx');
            }
            if (! $indexes->has('nba_pf_season_make_idx')) {
                $table->index(['season', 'playoff_make_probability'], 'nba_pf_season_make_idx');
            }
        });
    }
};
