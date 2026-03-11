<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->foreignId('nba_team_id')->nullable()->after('commence_time')->constrained('nba_teams')->nullOnDelete();
            $table->foreignId('mlb_team_id')->nullable()->after('nba_team_id')->constrained('mlb_teams')->nullOnDelete();
            $table->foreignId('nfl_team_id')->nullable()->after('mlb_team_id')->constrained('nfl_teams')->nullOnDelete();
        });

        DB::table('sports_futures_odds')
            ->where('team_type', \App\Models\NBA\Team::class)
            ->update(['nba_team_id' => DB::raw('team_id')]);

        DB::table('sports_futures_odds')
            ->where('team_type', \App\Models\MLB\Team::class)
            ->update(['mlb_team_id' => DB::raw('team_id')]);

        DB::table('sports_futures_odds')
            ->where('team_type', \App\Models\NFL\Team::class)
            ->update(['nfl_team_id' => DB::raw('team_id')]);

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('sports_futures_odds', function (Blueprint $table) {
                $table->dropIndex('sports_futures_odds_team_type_team_id_index');
            });
        }

        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->dropColumn(['team_type', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->nullableMorphs('team');
        });

        DB::table('sports_futures_odds')
            ->whereNotNull('nba_team_id')
            ->update([
                'team_type' => \App\Models\NBA\Team::class,
                'team_id' => DB::raw('nba_team_id'),
            ]);

        DB::table('sports_futures_odds')
            ->whereNotNull('mlb_team_id')
            ->update([
                'team_type' => \App\Models\MLB\Team::class,
                'team_id' => DB::raw('mlb_team_id'),
            ]);

        DB::table('sports_futures_odds')
            ->whereNotNull('nfl_team_id')
            ->update([
                'team_type' => \App\Models\NFL\Team::class,
                'team_id' => DB::raw('nfl_team_id'),
            ]);

        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nba_team_id');
            $table->dropConstrainedForeignId('mlb_team_id');
            $table->dropConstrainedForeignId('nfl_team_id');
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('sports_futures_odds', function (Blueprint $table) {
                $table->index(['team_type', 'team_id']);
            });
        }
    }
};
