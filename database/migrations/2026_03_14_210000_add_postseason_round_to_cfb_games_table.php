<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_games', function (Blueprint $table) {
            $table->unsignedTinyInteger('postseason_round')
                ->nullable()
                ->after('week');
            $table->index(['season', 'season_type', 'postseason_round'], 'cfb_games_postseason_round_index');
        });
    }

    public function down(): void
    {
        Schema::table('cfb_games', function (Blueprint $table) {
            $table->dropIndex('cfb_games_postseason_round_index');
            $table->dropColumn('postseason_round');
        });
    }
};
