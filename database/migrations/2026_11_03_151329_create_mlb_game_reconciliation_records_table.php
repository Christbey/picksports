<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_game_reconciliation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('mlb_games')->restrictOnDelete();
            $table->string('provider_event_id');
            $table->string('outcome');
            $table->string('reason');
            $table->json('before');
            $table->json('after');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('mlb_game_reconciliation_records')->exists()) {
            throw new RuntimeException('Cannot discard populated MLB reconciliation history.');
        }
        Schema::dropIfExists('mlb_game_reconciliation_records');
    }
};
