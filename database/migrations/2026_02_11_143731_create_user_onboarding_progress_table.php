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
        Schema::create('user_onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Progress tracking
            $table->string('current_step')->nullable(); // welcome, sport_selection, alert_setup, methodology_review, complete
            $table->json('completed_steps')->nullable(); // Array of completed step names
            $table->unsignedTinyInteger('progress_percentage')->default(0); // 0-100

            // Personalization data from survey
            $table->json('favorite_sports')->nullable(); // ['nba', 'nfl', 'mlb']
            $table->string('betting_experience')->nullable(); // beginner, intermediate, advanced
            $table->json('interests')->nullable(); // ['live_betting', 'parlays', 'prop_bets']
            $table->json('goals')->nullable(); // ['track_performance', 'find_value', 'learn_strategy']

            // Step-specific data
            $table->json('step_data')->nullable(); // Flexible JSON for any step-specific info

            // Completion tracking
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            // Email series tracking
            $table->unsignedTinyInteger('welcome_emails_sent')->default(0); // 0-5
            $table->timestamp('last_welcome_email_sent_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('current_step');
            $table->index('completed_at');
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_onboarding_progress');
    }
};
