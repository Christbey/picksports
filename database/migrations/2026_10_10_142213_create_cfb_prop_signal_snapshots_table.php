<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_prop_signal_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prop_id')->index();
            $table->unsignedBigInteger('game_id')->index();
            $table->unsignedBigInteger('player_id');
            $table->string('market');
            $table->string('bookmaker');
            $table->decimal('line', 10, 3);
            $table->integer('over_price');
            $table->integer('under_price');
            $table->timestamp('observed_at');
            $table->timestamp('kickoff_at')->index();
            $table->string('fingerprint', 64)->unique();
            $table->json('payload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_prop_signal_snapshots');
    }
};
