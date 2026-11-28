<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_elo_writer_gates', function (Blueprint $table): void {
            $table->string('sport', 8)->primary();
            $table->string('mode', 16)->default('disabled');
            $table->unsignedSmallInteger('protocol_version')->default(1);
            $table->string('calculation_version', 64)->nullable();
            $table->char('configuration_hash', 64)->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->string('drain_reference', 191)->nullable();
            $table->timestamp('drain_verified_at', 6)->nullable();
            $table->timestamps();
        });
        foreach (['nfl', 'nba', 'mlb', 'cbb', 'wcbb'] as $sport) {
            DB::table('sport_elo_writer_gates')->insert(['sport' => $sport, 'mode' => 'disabled', 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('sport_elo_season_initializations', function (Blueprint $table): void {
            $table->id();
            $table->string('sport', 8);
            $table->foreign('sport')->references('sport')->on('sport_elo_writer_gates')->restrictOnDelete();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedSmallInteger('season');
            $table->string('calculation_version', 64);
            $table->char('configuration_hash', 64);
            $table->string('kind', 32);
            $table->string('baseline_kind', 32);
            $table->decimal('rating_before', 12, 4);
            $table->decimal('rating_after', 12, 4);
            $table->decimal('prior_rating', 12, 4)->nullable();
            $table->decimal('regression_factor', 8, 7)->nullable();
            $table->unsignedBigInteger('prior_effect_id')->nullable();
            $table->string('source_history_table', 64)->nullable();
            $table->unsignedBigInteger('source_history_id')->nullable();
            $table->char('source_fingerprint', 64);
            $table->char('receipt_hash', 64);
            $table->json('evidence');
            $table->string('review_reference', 191)->nullable();
            $table->timestamp('observed_at', 6);
            $table->timestamp('recorded_at', 6);
            $table->unique(['sport', 'entity_type', 'entity_id', 'season', 'calculation_version'], 'sport_elo_initialization_identity');
            $table->index(['sport', 'season', 'calculation_version'], 'sport_elo_initialization_scope');
        });
        Schema::create('sport_elo_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('sport', 8);
            $table->foreign('sport')->references('sport')->on('sport_elo_writer_gates')->restrictOnDelete();
            $table->unsignedBigInteger('game_id');
            $table->unsignedSmallInteger('season');
            $table->string('calculation_version', 64);
            $table->char('configuration_hash', 64);
            $table->char('source_fingerprint', 64);
            $table->char('receipt_hash', 64);
            $table->dateTime('played_at', 6);
            $table->json('source_snapshot');
            $table->timestamp('recorded_at', 6);
            $table->unique(['sport', 'game_id', 'calculation_version'], 'sport_elo_application_identity');
            $table->index(['sport', 'played_at', 'game_id'], 'sport_elo_application_order');
        });
        Schema::create('sport_elo_application_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('sport_elo_applications')->restrictOnDelete();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->foreignId('initialization_id')->constrained('sport_elo_season_initializations')->restrictOnDelete();
            $table->foreignId('previous_effect_id')->nullable()->constrained('sport_elo_application_effects')->restrictOnDelete();
            $table->decimal('rating_before', 12, 4);
            $table->decimal('rating_after', 12, 4);
            $table->decimal('rating_delta', 12, 4);
            $table->unsignedInteger('games_started_before')->nullable();
            $table->unsignedInteger('games_started_after')->nullable();
            $table->json('evidence');
            $table->timestamp('recorded_at', 6);
            $table->unique(['application_id', 'entity_type', 'entity_id'], 'sport_elo_effect_identity');
            $table->index(['entity_type', 'entity_id', 'id'], 'sport_elo_effect_entity');
        });
        Schema::table('sport_elo_season_initializations', function (Blueprint $table): void {
            $table->foreign('prior_effect_id')->references('id')->on('sport_elo_application_effects')->restrictOnDelete();
        });
        Schema::create('sport_elo_entity_heads', function (Blueprint $table): void {
            $table->id();
            $table->string('sport', 8);
            $table->foreign('sport')->references('sport')->on('sport_elo_writer_gates')->restrictOnDelete();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('initialization_id')->constrained('sport_elo_season_initializations')->restrictOnDelete();
            $table->foreignId('last_effect_id')->nullable()->constrained('sport_elo_application_effects')->restrictOnDelete();
            $table->unsignedSmallInteger('season');
            $table->string('calculation_version', 64);
            $table->char('configuration_hash', 64);
            $table->decimal('rating', 12, 4);
            $table->char('source_fingerprint', 64);
            $table->dateTime('last_played_at', 6)->nullable();
            $table->unsignedBigInteger('last_game_id')->nullable();
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestamps();
            $table->unique(['sport', 'entity_type', 'entity_id'], 'sport_elo_head_identity');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain Elo coordination and audit receipts; use compatible code and a forward repair.');
    }
};
