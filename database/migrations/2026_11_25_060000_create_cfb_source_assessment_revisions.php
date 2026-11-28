<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_source_assessment_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_data_check_id')->constrained('cfb_game_data_checks')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('evidence_kind', 32);
            $table->char('context_hash', 64)->nullable();
            $table->char('assessment_hash', 64)->nullable();
            $table->char('receipt_hash', 64);
            $table->json('context_snapshot')->nullable();
            $table->json('projection_snapshot');
            $table->json('manifest_snapshot')->nullable();
            $table->timestamp('recorded_at', 6);
            $table->unique(['game_data_check_id', 'revision'], 'cfb_source_assessment_revision_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain CFB source assessment audit; deploy compatible code or a forward repair.');
    }
};
