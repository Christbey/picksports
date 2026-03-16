<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_games', function (Blueprint $table): void {
            $table->unsignedTinyInteger('home_seed')->nullable()->after('tournament_region');
            $table->unsignedTinyInteger('away_seed')->nullable()->after('home_seed');
            $table->unsignedTinyInteger('play_in_target_seed')->nullable()->after('away_seed');
        });
    }

    public function down(): void
    {
        Schema::table('cbb_games', function (Blueprint $table): void {
            $table->dropColumn([
                'home_seed',
                'away_seed',
                'play_in_target_seed',
            ]);
        });
    }
};
