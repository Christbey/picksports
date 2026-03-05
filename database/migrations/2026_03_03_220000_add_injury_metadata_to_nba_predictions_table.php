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
            $table->unsignedTinyInteger('home_injuries_out')->nullable()->after('form_spread_component');
            $table->unsignedTinyInteger('away_injuries_out')->nullable()->after('home_injuries_out');
            $table->unsignedTinyInteger('home_injuries_questionable')->nullable()->after('away_injuries_out');
            $table->unsignedTinyInteger('away_injuries_questionable')->nullable()->after('home_injuries_questionable');
            $table->decimal('injury_spread_adj', 5, 2)->nullable()->after('away_injuries_questionable');
            $table->decimal('injury_total_adj', 5, 2)->nullable()->after('injury_spread_adj');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nba_predictions', function (Blueprint $table) {
            $table->dropColumn([
                'home_injuries_out',
                'away_injuries_out',
                'home_injuries_questionable',
                'away_injuries_questionable',
                'injury_spread_adj',
                'injury_total_adj',
            ]);
        });
    }
};
