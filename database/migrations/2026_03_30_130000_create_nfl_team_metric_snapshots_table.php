<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_team_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_key', 40)->unique();
            $table->foreignId('team_id')->constrained('nfl_teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('season')->index();
            $table->unsignedTinyInteger('wins')->default(0);
            $table->unsignedTinyInteger('losses')->default(0);
            $table->decimal('predictive_rating', 10, 3)->nullable();
            $table->decimal('future_strength_of_schedule', 10, 3)->nullable();
            $table->decimal('recent_form_rating', 10, 3)->nullable();
            $table->decimal('injury_total_adjustment', 10, 3)->nullable();
            $table->date('calculation_date')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['season', 'captured_at']);
            $table->index(['team_id', 'season', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_team_metric_snapshots');
    }
};
