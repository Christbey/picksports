<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wnba_player_props')) {
            return;
        }

        Schema::create('wnba_player_props', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('wnba_games')->cascadeOnDelete();
            $table->unsignedBigInteger('player_id')->nullable()->index();
            $table->string('odds_api_event_id')->nullable()->index();
            $table->string('player_name');
            $table->string('market', 100)->index();
            $table->string('bookmaker', 50)->default('draftkings');
            $table->decimal('line', 8, 2)->nullable();
            $table->integer('over_price')->nullable();
            $table->integer('under_price')->nullable();
            $table->json('raw_data')->nullable();
            $table->decimal('actual_value', 8, 2)->nullable();
            $table->boolean('hit_over')->nullable();
            $table->decimal('error', 8, 2)->nullable();
            $table->string('recommended_side', 10)->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->decimal('predicted_over_probability', 5, 2)->nullable();
            $table->decimal('market_over_probability', 5, 2)->nullable();
            $table->decimal('edge_probability', 5, 2)->nullable();
            $table->unsignedTinyInteger('data_quality_score')->nullable();
            $table->unsignedTinyInteger('match_quality_score')->nullable();
            $table->decimal('context_adjustment_factor', 6, 3)->nullable();
            $table->json('confidence_decomposition')->nullable();
            $table->json('narrative_json')->nullable();
            $table->string('narrative_provider', 32)->nullable();
            $table->string('narrative_model', 64)->nullable();
            $table->string('narrative_input_hash', 64)->nullable();
            $table->unsignedInteger('narrative_latency_ms')->nullable();
            $table->timestamp('narrative_generated_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'market']);
            $table->index(['player_id', 'market']);
            $table->index(['fetched_at']);
            $table->index('graded_at');
            $table->index('narrative_input_hash');
            $table->index('narrative_generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wnba_player_props');
    }
};
