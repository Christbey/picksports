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
        Schema::create('user_alerts_sent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sport'); // nfl, nba, cbb, etc.
            $table->string('alert_type')->default('betting_value'); // betting_value, injury, etc.
            $table->unsignedBigInteger('prediction_id')->nullable();
            $table->string('prediction_type')->nullable(); // App\Models\NBA\Prediction, etc.
            $table->decimal('expected_value', 8, 2)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['user_id', 'sent_at']);
            $table->index(['user_id', 'sport', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_alerts_sent');
    }
};
