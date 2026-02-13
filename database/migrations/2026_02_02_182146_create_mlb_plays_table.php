<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mlb_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('mlb_games')->onDelete('cascade');
            $table->string('espn_play_id', 50)->nullable();
            $table->integer('sequence_number')->nullable();
            $table->integer('inning')->nullable();
            $table->string('inning_half', 10)->nullable();
            $table->string('play_type', 50)->nullable();
            $table->text('play_text')->nullable();
            $table->integer('score_value')->nullable();
            $table->boolean('is_at_bat')->default(false);
            $table->boolean('is_scoring_play')->default(false);
            $table->boolean('is_out')->default(false);
            $table->integer('balls')->nullable();
            $table->integer('strikes')->nullable();
            $table->integer('outs')->nullable();
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->foreignId('batting_team_id')->nullable()->constrained('mlb_teams')->onDelete('set null');
            $table->foreignId('pitching_team_id')->nullable()->constrained('mlb_teams')->onDelete('set null');
            $table->timestamps();

            $table->index('game_id');
            $table->index('inning');
            $table->index('play_type');
            $table->index('batting_team_id');
            $table->index(['game_id', 'sequence_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_plays');
    }
};
