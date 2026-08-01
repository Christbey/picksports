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
        Schema::create('cfb_preseason_team_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cfb_teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('season');

            $table->decimal('returning_percent_ppa', 6, 3)->nullable();
            $table->decimal('returning_percent_passing_ppa', 6, 3)->nullable();
            $table->decimal('returning_percent_rushing_ppa', 6, 3)->nullable();
            $table->decimal('returning_percent_receiving_ppa', 6, 3)->nullable();
            $table->decimal('returning_usage', 6, 3)->nullable();
            $table->decimal('returning_passing_usage', 6, 3)->nullable();
            $table->decimal('returning_rushing_usage', 6, 3)->nullable();
            $table->decimal('returning_receiving_usage', 6, 3)->nullable();
            $table->decimal('returning_total_ppa', 8, 3)->nullable();
            $table->decimal('returning_total_passing_ppa', 8, 3)->nullable();
            $table->decimal('returning_total_rushing_ppa', 8, 3)->nullable();
            $table->decimal('returning_total_receiving_ppa', 8, 3)->nullable();
            $table->json('returning_production_payload')->nullable();

            $table->unsignedSmallInteger('incoming_transfer_count')->default(0);
            $table->unsignedSmallInteger('outgoing_transfer_count')->default(0);
            $table->decimal('incoming_transfer_value', 8, 3)->default(0);
            $table->decimal('outgoing_transfer_value', 8, 3)->default(0);
            $table->decimal('transfer_net_value', 8, 3)->default(0);
            $table->decimal('transfer_qb_net_value', 8, 3)->default(0);
            $table->decimal('transfer_ol_net_value', 8, 3)->default(0);
            $table->decimal('transfer_dl_net_value', 8, 3)->default(0);
            $table->decimal('transfer_wr_net_value', 8, 3)->default(0);
            $table->decimal('transfer_cb_net_value', 8, 3)->default(0);
            $table->json('transfer_position_summary')->nullable();
            $table->json('transfer_portal_payload')->nullable();

            $table->decimal('talent_composite', 8, 3)->nullable();
            $table->unsignedSmallInteger('talent_rank')->nullable();
            $table->unsignedSmallInteger('recruiting_rank')->nullable();
            $table->decimal('recruiting_points', 8, 3)->nullable();
            $table->decimal('recruiting_avg_rating', 8, 4)->nullable();
            $table->json('talent_payload')->nullable();
            $table->json('recruiting_payload')->nullable();

            $table->string('qb_continuity_classification', 40)->default('unknown')->index();
            $table->decimal('qb_continuity_confidence', 5, 3)->nullable();
            $table->string('projected_starting_qb_name')->nullable();
            $table->string('projected_starting_qb_source')->nullable();
            $table->json('qb_continuity_payload')->nullable();

            $table->boolean('new_head_coach')->nullable();
            $table->boolean('new_offensive_coordinator')->nullable();
            $table->boolean('new_defensive_coordinator')->nullable();
            $table->decimal('coordinator_continuity_score', 5, 3)->nullable();
            $table->string('head_coach_name')->nullable();
            $table->string('offensive_coordinator_name')->nullable();
            $table->string('defensive_coordinator_name')->nullable();
            $table->json('coaching_continuity_payload')->nullable();

            $table->string('data_quality_status', 40)->default('partial')->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'season']);
            $table->index('season');
            $table->index(['season', 'talent_rank']);
            $table->index(['season', 'recruiting_rank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfb_preseason_team_signals');
    }
};
