<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_live_prediction_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('cfb_games')->cascadeOnDelete();
            $table->unsignedBigInteger('prediction_id')->nullable();
            $table->string('source', 30);
            $table->string('state_hash', 64)->unique();
            $table->json('pregame');
            $table->json('state');
            $table->json('projection')->nullable();
            $table->json('markets');
            $table->json('props');
            $table->string('status', 40);
            $table->timestamp('observed_at');
            $table->index(['game_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_live_prediction_snapshots');
    }
};
