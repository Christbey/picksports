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
        Schema::create('player_props', function (Blueprint $table) {
            $table->id();
            $table->morphs('gameable'); // polymorphic relation to nba_games, cbb_games, etc.
            $table->unsignedBigInteger('player_id')->nullable()->index(); // No FK constraint - sport-specific players
            $table->string('sport', 50)->index();
            $table->string('odds_api_event_id')->nullable()->index();
            $table->string('player_name');
            $table->string('market', 100)->index(); // e.g., 'player_points', 'player_rebounds'
            $table->string('bookmaker', 50)->default('draftkings');
            $table->decimal('line', 8, 2)->nullable(); // The prop line (e.g., 25.5 points)
            $table->integer('over_price')->nullable(); // American odds for over (e.g., -110)
            $table->integer('under_price')->nullable(); // American odds for under (e.g., -110)
            $table->json('raw_data')->nullable(); // Store full outcome data from API
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index(['gameable_type', 'gameable_id', 'market']);
            $table->index(['player_id', 'market']);
            $table->index(['sport', 'fetched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_props');
    }
};
