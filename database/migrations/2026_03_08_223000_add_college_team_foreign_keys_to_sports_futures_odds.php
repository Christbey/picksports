<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->foreignId('cbb_team_id')->nullable()->after('nfl_team_id')->constrained('cbb_teams')->nullOnDelete();
            $table->foreignId('wcbb_team_id')->nullable()->after('cbb_team_id')->constrained('wcbb_teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cbb_team_id');
            $table->dropConstrainedForeignId('wcbb_team_id');
        });
    }
};
