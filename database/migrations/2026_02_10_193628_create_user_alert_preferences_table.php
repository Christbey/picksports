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
        Schema::create('user_alert_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->boolean('enabled')->default(false);

            // MySQL 8.4.6 doesn't support default values for JSON columns
            $table->json('sports');

            $table->json('notification_types');

            $table->decimal('minimum_edge', 5, 2)->default(5.00);

            $table->time('time_window_start')->default('09:00:00');
            $table->time('time_window_end')->default('23:00:00');

            $table->enum('digest_mode', ['realtime', 'daily_summary'])->default('realtime');
            $table->time('digest_time')->nullable();

            $table->string('phone_number')->nullable();

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_alert_preferences');
    }
};
