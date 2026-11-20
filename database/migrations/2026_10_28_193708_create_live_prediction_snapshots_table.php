<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_prediction_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 10);
            $table->string('game_table', 40);
            $table->unsignedBigInteger('game_id');
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->nullOnDelete();
            $table->json('baseline')->nullable();
            $table->json('state');
            $table->json('projection')->nullable();
            $table->string('status', 40);
            $table->string('calculator');
            $table->char('state_hash', 64)->unique();
            $table->timestamp('observed_at');
            $table->index(['sport', 'game_id', 'observed_at', 'id'], 'live_predictions_game_observed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_prediction_snapshots');
    }
};
