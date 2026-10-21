<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['nba_games', 'cbb_games', 'wcbb_games'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('neutral_site')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['nba_games', 'cbb_games', 'wcbb_games'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('neutral_site');
            });
        }
    }
};
