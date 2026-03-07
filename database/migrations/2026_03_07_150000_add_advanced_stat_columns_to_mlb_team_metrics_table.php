<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_team_metrics', function (Blueprint $table) {
            $table->decimal('run_differential_per_game', 6, 2)->nullable()->after('runs_allowed_per_game');
            $table->decimal('home_runs_per_game', 5, 2)->nullable()->after('run_differential_per_game');
            $table->decimal('on_base_percentage', 6, 3)->nullable()->after('batting_average');
            $table->decimal('slugging_percentage', 6, 3)->nullable()->after('on_base_percentage');
            $table->decimal('ops', 6, 3)->nullable()->after('slugging_percentage');
            $table->decimal('strikeouts_pitched_per_game', 5, 2)->nullable()->after('team_era');
            $table->decimal('whip', 6, 3)->nullable()->after('strikeouts_pitched_per_game');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_team_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'run_differential_per_game',
                'home_runs_per_game',
                'on_base_percentage',
                'slugging_percentage',
                'ops',
                'strikeouts_pitched_per_game',
                'whip',
            ]);
        });
    }
};
