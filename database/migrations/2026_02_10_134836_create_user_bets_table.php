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
        Schema::create('user_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Polymorphic relationship to predictions across all sports
            $table->morphs('prediction');  // creates prediction_id and prediction_type

            // Bet details
            $table->decimal('bet_amount', 10, 2);
            $table->string('odds');  // e.g., "-110", "+150"
            $table->enum('bet_type', ['spread', 'moneyline', 'total_over', 'total_under']);

            // Result tracking
            $table->enum('result', ['pending', 'won', 'lost', 'push'])->default('pending');
            $table->decimal('profit_loss', 10, 2)->nullable();

            // Metadata
            $table->text('notes')->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'result']);
            $table->index('placed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_bets');
    }
};
