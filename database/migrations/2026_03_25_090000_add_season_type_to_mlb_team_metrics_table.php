<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_team_metrics', function (Blueprint $table) {
            $table->string('season_type')->nullable()->after('season');
            $table->index('team_id', 'mlb_team_metrics_team_id_idx');
        });

        DB::table('mlb_team_metrics')
            ->whereNull('season_type')
            ->update(['season_type' => (string) config('mlb.season.types.regular', 2)]);

        Schema::table('mlb_team_metrics', function (Blueprint $table) {
            $table->string('season_type')->default((string) config('mlb.season.types.regular', 2))->nullable(false)->change();
            $table->dropUnique(['team_id', 'season']);
            $table->unique(['team_id', 'season', 'season_type']);
        });
    }

    public function down(): void
    {
        Schema::table('mlb_team_metrics', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'season', 'season_type']);
            $table->unique(['team_id', 'season']);
            $table->dropIndex('mlb_team_metrics_team_id_idx');
            $table->dropColumn('season_type');
        });
    }
};
