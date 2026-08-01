<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_prediction_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('season')->index();
            $table->unsignedTinyInteger('training_from_week')->default(0);
            $table->unsignedTinyInteger('training_through_week')->nullable();
            $table->unsignedSmallInteger('games_count')->default(0);
            $table->unsignedSmallInteger('min_games')->default(8);
            $table->decimal('learning_rate', 5, 3)->default(0.250);
            $table->json('parameters');
            $table->json('metrics')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['season', 'is_active', 'generated_at'], 'cfb_calibrations_active_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_prediction_calibrations');
    }
};
