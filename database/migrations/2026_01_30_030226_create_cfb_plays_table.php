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
        Schema::create('cfb_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('cfb_games')->onDelete('cascade');
            $table->string('espn_play_id', 50)->nullable();
            $table->integer('sequence_number')->nullable();
            $table->integer('period')->nullable();
            $table->string('clock', 20)->nullable();
            $table->string('play_type', 50)->nullable();
            $table->text('play_text')->nullable();
            $table->integer('down')->nullable();
            $table->integer('distance')->nullable();
            $table->integer('yards_to_endzone')->nullable();
            $table->integer('yards_gained')->nullable();
            $table->boolean('is_scoring_play')->default(false);
            $table->boolean('is_turnover')->default(false);
            $table->boolean('is_penalty')->default(false);
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->foreignId('possession_team_id')->nullable()->constrained('cfb_teams')->onDelete('set null');
            $table->timestamps();

            $table->index('game_id');
            $table->index('period');
            $table->index('play_type');
            $table->index('is_scoring_play');
            $table->index('possession_team_id');
            $table->index(['game_id', 'sequence_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfb_plays');
    }
};
