<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nba_predictions', function (Blueprint $table) {
            $table->decimal('home_recent_form', 5, 3)->nullable()->after('away_def_eff');
            $table->decimal('away_recent_form', 5, 3)->nullable()->after('home_recent_form');
            $table->tinyInteger('rest_days_home')->nullable()->after('away_recent_form');
            $table->tinyInteger('rest_days_away')->nullable()->after('rest_days_home');
            $table->decimal('home_away_split_adj', 5, 2)->nullable()->after('rest_days_away');
            $table->decimal('turnover_diff_adj', 5, 2)->nullable()->after('home_away_split_adj');
            $table->decimal('rebound_margin_adj', 5, 2)->nullable()->after('turnover_diff_adj');
            $table->decimal('vegas_spread', 5, 2)->nullable()->after('rebound_margin_adj');
            $table->decimal('elo_spread_component', 5, 2)->nullable()->after('vegas_spread');
            $table->decimal('efficiency_spread_component', 5, 2)->nullable()->after('elo_spread_component');
            $table->decimal('form_spread_component', 5, 2)->nullable()->after('efficiency_spread_component');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nba_predictions', function (Blueprint $table) {
            $table->dropColumn([
                'home_recent_form',
                'away_recent_form',
                'rest_days_home',
                'rest_days_away',
                'home_away_split_adj',
                'turnover_diff_adj',
                'rebound_margin_adj',
                'vegas_spread',
                'elo_spread_component',
                'efficiency_spread_component',
                'form_spread_component',
            ]);
        });
    }
};
