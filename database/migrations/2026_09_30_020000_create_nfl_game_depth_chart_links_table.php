<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL keeps completed DDL when a later constraint fails. Resume the
        // initial deployment without dropping any recorded evidence.
        if (Schema::hasTable('nfl_game_depth_chart_links')) {
            $foreignKeys = array_column(Schema::getForeignKeys('nfl_game_depth_chart_links'), 'columns');
            $indexes = array_column(Schema::getIndexes('nfl_game_depth_chart_links'), 'name');
            Schema::table('nfl_game_depth_chart_links', function (Blueprint $table) use ($foreignKeys, $indexes): void {
                if (! in_array(['prediction_feature_snapshot_id'], $foreignKeys, true)) {
                    $table->foreign('prediction_feature_snapshot_id', 'nfl_depth_link_revision_fk')->references('id')->on('prediction_feature_snapshots')->restrictOnDelete();
                }
                if (! in_array('nfl_depth_link_revision_side_unique', $indexes, true)) {
                    $table->unique(['prediction_feature_snapshot_id', 'side'], 'nfl_depth_link_revision_side_unique');
                }
                if (! in_array('nfl_depth_link_game_history_index', $indexes, true)) {
                    $table->index(['game_id', 'side', 'id'], 'nfl_depth_link_game_history_index');
                }
            });

            return;
        }

        Schema::create('nfl_game_depth_chart_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('nfl_games')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('nfl_teams')->restrictOnDelete();
            $table->string('side', 4);
            $table->foreignId('snapshot_id')->constrained('nfl_depth_chart_snapshots')->restrictOnDelete();
            $table->foreignId('prediction_feature_snapshot_id')->constrained('prediction_feature_snapshots', indexName: 'nfl_depth_link_revision_fk')->restrictOnDelete();
            $table->timestamp('observed_at');
            $table->timestamp('as_of');
            $table->timestamp('selected_at');
            $table->string('source');
            $table->string('identity_status');
            $table->string('selection_mode');
            $table->json('evidence')->nullable();
            $table->unique(['prediction_feature_snapshot_id', 'side'], 'nfl_depth_link_revision_side_unique');
            $table->index(['game_id', 'side', 'id'], 'nfl_depth_link_game_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_game_depth_chart_links');
    }
};
