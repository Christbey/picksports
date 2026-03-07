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
        foreach (['nba_player_props', 'cbb_player_props', 'nfl_player_props', 'mlb_player_props'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'recommended_side')) {
                    $table->string('recommended_side', 10)->nullable()->after('error');
                }
                if (! Schema::hasColumn($tableName, 'confidence_score')) {
                    $table->unsignedTinyInteger('confidence_score')->nullable()->after('recommended_side');
                }
                if (! Schema::hasColumn($tableName, 'predicted_over_probability')) {
                    $table->decimal('predicted_over_probability', 5, 2)->nullable()->after('confidence_score');
                }
                if (! Schema::hasColumn($tableName, 'market_over_probability')) {
                    $table->decimal('market_over_probability', 5, 2)->nullable()->after('predicted_over_probability');
                }
                if (! Schema::hasColumn($tableName, 'edge_probability')) {
                    $table->decimal('edge_probability', 5, 2)->nullable()->after('market_over_probability');
                }
                if (! Schema::hasColumn($tableName, 'data_quality_score')) {
                    $table->unsignedTinyInteger('data_quality_score')->nullable()->after('edge_probability');
                }
                if (! Schema::hasColumn($tableName, 'match_quality_score')) {
                    $table->unsignedTinyInteger('match_quality_score')->nullable()->after('data_quality_score');
                }
                if (! Schema::hasColumn($tableName, 'context_adjustment_factor')) {
                    $table->decimal('context_adjustment_factor', 6, 3)->nullable()->after('match_quality_score');
                }
                if (! Schema::hasColumn($tableName, 'confidence_decomposition')) {
                    $table->json('confidence_decomposition')->nullable()->after('context_adjustment_factor');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['nba_player_props', 'cbb_player_props', 'nfl_player_props', 'mlb_player_props'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach ([
                    'recommended_side',
                    'confidence_score',
                    'predicted_over_probability',
                    'market_over_probability',
                    'edge_probability',
                    'data_quality_score',
                    'match_quality_score',
                    'context_adjustment_factor',
                    'confidence_decomposition',
                ] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
