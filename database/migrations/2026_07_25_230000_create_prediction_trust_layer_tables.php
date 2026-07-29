<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('sport', 16)->index();
            $table->string('run_type', 32)->default('prediction');
            $table->string('model_version', 64);
            $table->string('feature_version', 64);
            $table->string('blend_version', 64);
            $table->string('config_hash', 64);
            $table->string('code_version', 64)->nullable();
            $table->string('artifact_path')->nullable();
            $table->string('artifact_hash', 64)->nullable();
            $table->json('parameters')->nullable();
            $table->string('status', 24)->default('completed')->index();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sport', 'started_at']);
            $table->index(['sport', 'model_version', 'feature_version', 'blend_version'], 'model_runs_version_lookup');
        });

        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->foreignUuid('model_run_id')
                ->nullable()
                ->after('snapshot_run_id')
                ->constrained('model_runs')
                ->nullOnDelete();
        });

        Schema::create('market_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_odds_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 16)->index();
            $table->string('game_table', 64);
            $table->unsignedBigInteger('game_id');
            $table->string('source', 32);
            $table->string('bookmaker_key', 64)->nullable();
            $table->string('bookmaker_title', 128)->nullable();
            $table->string('market_key', 80);
            $table->string('side', 80);
            $table->string('participant', 160)->nullable();
            $table->decimal('line', 10, 3)->nullable();
            $table->integer('price')->nullable();
            $table->decimal('bookmaker_home_line', 10, 3)->nullable();
            $table->decimal('home_margin_equivalent', 10, 3)->nullable();
            $table->decimal('implied_probability', 9, 6)->nullable();
            $table->decimal('no_vig_probability', 9, 6)->nullable();
            $table->timestamp('commence_time')->nullable();
            $table->timestamp('captured_at');
            $table->boolean('is_pregame')->nullable()->index();
            $table->string('quote_hash', 64)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sport', 'game_table', 'game_id', 'captured_at'], 'market_quotes_game_lookup');
            $table->index(['market_key', 'bookmaker_key', 'captured_at'], 'market_quotes_market_lookup');
        });

        Schema::create('bet_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('decision_run_id')->index();
            $table->foreignUuid('model_run_id')->nullable()->constrained('model_runs')->nullOnDelete();
            $table->foreignId('prediction_feature_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_odds_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_table', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('sport', 16)->index();
            $table->string('game_table', 64);
            $table->unsignedBigInteger('game_id');
            $table->string('prediction_table', 64)->nullable();
            $table->unsignedBigInteger('prediction_id')->nullable();
            $table->string('market_type', 40);
            $table->string('market_key', 80);
            $table->string('side', 80);
            $table->decimal('line', 10, 3)->nullable();
            $table->integer('price')->nullable();
            $table->string('bookmaker', 80)->nullable();
            $table->decimal('market_probability', 9, 6)->nullable();
            $table->decimal('no_vig_probability', 9, 6)->nullable();
            $table->decimal('model_probability', 9, 6)->nullable();
            $table->decimal('blend_probability', 9, 6)->nullable();
            $table->decimal('edge', 9, 6)->nullable();
            $table->decimal('projected_value', 10, 4)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->decimal('confidence', 9, 6)->nullable();
            $table->string('status', 32);
            $table->string('recommendation_label', 40)->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_tracking_only')->default(true);
            $table->boolean('is_bet')->default(false)->index();
            $table->boolean('pregame_safe')->default(false)->index();
            $table->json('eligibility_reasons')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('reason_codes')->nullable();
            $table->json('feature_snapshot')->nullable();
            $table->json('market_snapshot')->nullable();
            $table->timestamp('decided_at')->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('game_start_at')->nullable();
            $table->string('decision_hash', 64)->unique();
            $table->timestamps();

            $table->index(['source_table', 'source_id']);
            $table->index(['sport', 'game_table', 'game_id', 'decided_at'], 'bet_decisions_game_lookup');
            $table->index(['sport', 'market_type', 'is_bet', 'pregame_safe'], 'bet_decisions_performance_lookup');
        });

        Schema::create('bet_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bet_decision_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('result_status', 24)->index();
            $table->decimal('result_value', 10, 4)->nullable();
            $table->decimal('profit_units', 10, 4);
            $table->integer('closing_price')->nullable();
            $table->decimal('closing_line', 10, 3)->nullable();
            $table->decimal('clv', 10, 6)->nullable();
            $table->timestamp('graded_at');
            $table->timestamp('settled_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('mlb_pick_candidates', function (Blueprint $table): void {
            $table->uuid('generation_run_id')->nullable()->after('id')->index();
            $table->string('decision_hash', 64)->nullable()->after('generation_run_id')->index();
            $table->timestamp('superseded_at')->nullable()->after('generated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mlb_pick_candidates', function (Blueprint $table): void {
            $table->dropColumn(['generation_run_id', 'decision_hash', 'superseded_at']);
        });

        Schema::dropIfExists('bet_settlements');
        Schema::dropIfExists('bet_decisions');
        Schema::dropIfExists('market_quotes');

        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('model_run_id');
        });

        Schema::dropIfExists('model_runs');
    }
};
