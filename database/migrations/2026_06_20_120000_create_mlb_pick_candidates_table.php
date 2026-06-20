<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_pick_candidates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('season')->index();
            $table->foreignId('game_id')->constrained('mlb_games')->cascadeOnDelete();
            $table->foreignId('prediction_id')->nullable()->constrained('mlb_predictions')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('mlb_teams')->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('mlb_players')->nullOnDelete();
            $table->string('market_type', 40)->index();
            $table->string('market_key', 80)->index();
            $table->string('side', 40);
            $table->decimal('line', 8, 2)->nullable();
            $table->integer('price')->nullable();
            $table->string('book', 80)->nullable();
            $table->decimal('market_probability', 7, 4)->nullable();
            $table->decimal('no_vig_probability', 7, 4)->nullable();
            $table->decimal('model_probability', 7, 4)->nullable();
            $table->decimal('blend_probability', 7, 4)->nullable();
            $table->decimal('edge_raw', 7, 4)->nullable();
            $table->decimal('edge_no_vig', 7, 4)->nullable();
            $table->decimal('projected_value', 8, 3)->nullable();
            $table->unsignedTinyInteger('score')->default(0)->index();
            $table->decimal('confidence', 7, 4)->nullable();
            $table->string('status', 32)->default('tracking_only')->index();
            $table->string('recommendation_label', 40)->default('tracking_only')->index();
            $table->boolean('is_public')->default(false)->index();
            $table->boolean('is_tracking_only')->default(true);
            $table->boolean('is_bet')->default(false)->index();
            $table->json('risk_flags')->nullable();
            $table->json('reason_codes')->nullable();
            $table->json('feature_snapshot')->nullable();
            $table->json('market_snapshot')->nullable();
            $table->timestamp('generated_at')->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('game_start_at')->nullable()->index();
            $table->string('result_status', 32)->nullable()->index();
            $table->decimal('result_value', 8, 3)->nullable();
            $table->decimal('result_profit_units', 8, 3)->nullable();
            $table->integer('closing_price')->nullable();
            $table->decimal('closing_line', 8, 2)->nullable();
            $table->decimal('clv', 8, 4)->nullable();
            $table->timestamp('graded_at')->nullable()->index();
            $table->timestamps();

            $table->index(['season', 'market_type', 'score']);
            $table->index(['game_id', 'market_type', 'side']);
            $table->index(['generated_at', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_pick_candidates');
    }
};
