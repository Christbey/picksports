<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createDepthChartTable('nfl');
        $this->createDepthChartTable('nba');
        $this->createDepthChartTable('mlb');
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_depth_chart_entries');
        Schema::dropIfExists('nba_depth_chart_entries');
        Schema::dropIfExists('nfl_depth_chart_entries');
    }

    private function createDepthChartTable(string $sport): void
    {
        Schema::create("{$sport}_depth_chart_entries", function (Blueprint $table) use ($sport) {
            $table->id();
            $table->foreignId('team_id')->constrained("{$sport}_teams")->onDelete('cascade');
            $table->foreignId('player_id')->nullable()->constrained("{$sport}_players")->nullOnDelete();
            $table->unsignedSmallInteger('season');
            $table->string('espn_depth_chart_id', 100)->nullable();
            $table->string('depth_chart_name', 150)->nullable();
            $table->string('position_slot_key', 50);
            $table->string('position_espn_id', 50)->nullable();
            $table->string('position_code', 50)->nullable();
            $table->string('position_name', 150)->nullable();
            $table->string('position_display_name', 150)->nullable();
            $table->string('espn_athlete_id', 50)->nullable();
            $table->unsignedInteger('slot_order')->nullable();
            $table->unsignedInteger('depth_rank')->default(1);
            $table->boolean('is_starter')->default(false);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['team_id', 'season', 'espn_depth_chart_id', 'position_slot_key', 'depth_rank', 'espn_athlete_id'],
                "{$sport}_depth_chart_entries_unique"
            );
            $table->index(['team_id', 'season'], "{$sport}_depth_chart_team_season_index");
            $table->index(['team_id', 'season', 'position_slot_key'], "{$sport}_depth_chart_position_index");
            $table->index(['team_id', 'season', 'is_starter'], "{$sport}_depth_chart_starter_index");
            $table->index('espn_athlete_id', "{$sport}_depth_chart_athlete_index");
        });
    }
};
