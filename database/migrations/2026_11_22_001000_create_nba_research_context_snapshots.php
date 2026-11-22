<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nba_research_context_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('nba_games');
            $table->foreignId('source_id')->constrained('research_sources');
            $table->string('identity_hash', 64);
            $table->string('capture_hash', 64);
            $table->json('responses');
            $table->timestamp('captured_at');
            $table->unique(['game_id', 'capture_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nba_research_context_snapshots');
    }
};
