<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_game_context_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('nfl_games');
            $table->foreignId('team_id')->constrained('nfl_teams');
            $table->string('kind', 32);
            $table->string('subject');
            $table->string('gsis_id')->nullable();
            $table->string('position', 32)->nullable();
            $table->string('designation')->nullable();
            $table->string('injury')->nullable();
            $table->string('participation')->nullable();
            $table->string('key_reason')->nullable();
            $table->text('source_url');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('recorded_at');
            $table->string('evidence_hash', 64)->unique();
            $table->json('evidence');
            $table->index(['game_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_game_context_facts');
    }
};
