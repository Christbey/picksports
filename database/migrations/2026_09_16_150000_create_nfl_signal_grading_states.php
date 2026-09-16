<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_signal_grading_states', function (Blueprint $table): void {
            $table->foreignId('nfl_signal_observation_id')->primary()
                ->constrained('nfl_signal_observations')->cascadeOnDelete();
            $table->integer('home_score');
            $table->integer('away_score');
            $table->unsignedTinyInteger('outcome_grade_count');
            $table->timestamp('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_signal_grading_states');
    }
};
