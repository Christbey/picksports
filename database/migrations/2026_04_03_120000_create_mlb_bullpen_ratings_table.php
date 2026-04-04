<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_bullpen_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('mlb_teams')->onDelete('cascade');
            $table->unsignedInteger('season');
            $table->string('season_type', 32);
            $table->date('as_of_date');
            $table->unsignedInteger('games_sampled')->default(0);
            $table->decimal('weighted_usage', 6, 3)->nullable();
            $table->decimal('weighted_era', 6, 3)->nullable();
            $table->decimal('weighted_whip', 6, 3)->nullable();
            $table->decimal('strikeouts_per_nine', 6, 3)->nullable();
            $table->decimal('walks_per_nine', 6, 3)->nullable();
            $table->decimal('home_runs_per_nine', 6, 3)->nullable();
            $table->decimal('recent_form_score', 6, 3)->nullable();
            $table->decimal('workload_penalty', 6, 3)->nullable();
            $table->decimal('rating_score', 7, 3);
            $table->unsignedInteger('rating_rank')->nullable();
            $table->date('calculation_date');
            $table->timestamps();

            $table->unique(['team_id', 'season', 'season_type', 'as_of_date'], 'mlb_bullpen_unique_snapshot');
            $table->index(['season', 'season_type', 'as_of_date'], 'mlb_bullpen_snapshot_lookup');
            $table->index(['season', 'season_type', 'as_of_date', 'rating_rank'], 'mlb_bullpen_snapshot_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_bullpen_ratings');
    }
};
