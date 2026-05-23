<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_bet_filter_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('mlb_games')->cascadeOnDelete();
            $table->foreignId('prediction_id')->constrained('mlb_predictions')->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->string('season_type', 8)->nullable();
            $table->date('game_date')->nullable();
            $table->date('as_of_date');
            $table->string('filter_version', 64)->default('selective_mlb_bet_filter_v1');
            $table->string('market', 24);
            $table->string('pick_side', 16);
            $table->foreignId('team_id')->nullable()->constrained('mlb_teams')->nullOnDelete();
            $table->string('team_name')->nullable();
            $table->unsignedTinyInteger('score');
            $table->string('classification', 16);
            $table->decimal('model_probability', 7, 4)->nullable();
            $table->integer('market_price')->nullable();
            $table->decimal('market_implied_probability', 7, 4)->nullable();
            $table->decimal('probability_edge', 7, 4)->nullable();
            $table->decimal('edge_runs', 6, 2)->nullable();
            $table->decimal('model_line', 6, 2)->nullable();
            $table->decimal('market_line', 6, 2)->nullable();
            $table->decimal('closing_line', 6, 2)->nullable();
            $table->integer('closing_price')->nullable();
            $table->decimal('clv', 7, 3)->nullable();
            $table->json('reason_codes')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('result_hit')->nullable();
            $table->decimal('actual_margin', 6, 1)->nullable();
            $table->decimal('actual_total', 6, 1)->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['prediction_id', 'market', 'pick_side', 'as_of_date'], 'mlb_bet_filter_unique_snapshot');
            $table->index(['season', 'market', 'classification'], 'mlb_bet_filter_market_class_lookup');
            $table->index(['season', 'graded_at'], 'mlb_bet_filter_graded_lookup');
            $table->index(['game_date', 'classification'], 'mlb_bet_filter_slate_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_bet_filter_results');
    }
};
