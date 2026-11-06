<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbb_roster_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('roster_snapshot_id')->constrained('cbb_roster_snapshots');
            $table->foreignId('player_id')->constrained('cbb_players');
            $table->json('attributes');
            $table->unique(['roster_snapshot_id', 'player_id'], 'cbb_roster_member');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbb_roster_entries');
    }
};
