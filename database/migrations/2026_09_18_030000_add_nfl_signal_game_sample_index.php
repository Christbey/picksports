<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_signal_observations', function (Blueprint $table) {
            $table->index(['game_id', 'signal_type', 'signal_key', 'pregame_safe', 'observed_at', 'id'], 'nfl_signal_game_sample_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('nfl_signal_observations', fn (Blueprint $table) => $table->dropIndex('nfl_signal_game_sample_lookup'));
    }
};
