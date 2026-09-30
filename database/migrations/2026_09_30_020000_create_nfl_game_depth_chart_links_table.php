<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_game_depth_chart_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('nfl_games')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('nfl_teams')->restrictOnDelete();
            $table->string('side', 4);
            $table->foreignId('snapshot_id')->constrained('nfl_depth_chart_snapshots')->restrictOnDelete();
            $table->foreignId('prediction_feature_snapshot_id')->constrained('prediction_feature_snapshots')->restrictOnDelete();
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
