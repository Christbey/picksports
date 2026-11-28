<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_prop_grade_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('sport', 40);
            $table->string('prop_table', 40);
            $table->unsignedBigInteger('prop_id');
            foreach (['nba', 'cbb', 'wnba', 'nfl', 'cfb', 'mlb'] as $sport) {
                $table->foreignId($sport.'_prop_id')->nullable()->constrained($sport.'_player_props')->restrictOnDelete();
            }
            $table->unsignedBigInteger('game_id');
            $table->unsignedInteger('revision');
            $table->string('policy_version', 40);
            $table->char('entry_hash', 64);
            $table->char('stat_fingerprint', 64);
            $table->string('state', 20);
            $table->string('reason', 60);
            $table->json('entry_snapshot');
            $table->json('stat_snapshot');
            $table->json('previous_projection');
            $table->json('projection');
            $table->timestamp('created_at');
            $table->unique(['prop_table', 'prop_id', 'revision'], 'prop_grade_revision_identity_unique');
            $table->index(['sport', 'game_id', 'id'], 'prop_grade_game_index');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain append-only prop grading evidence; quiesce workers and repair forward before rollback.');
    }
};
