<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_matchup_schedule_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('nfl_games')->restrictOnDelete();
            $table->string('evidence_hash', 64);
            $table->string('source_sha256', 64);
            $table->timestamp('observed_at');
            $table->json('evidence');
            $table->unique(['game_id', 'evidence_hash'], 'nfl_matchup_schedule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_matchup_schedule_evidence');
    }
};
