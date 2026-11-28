<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('billing_trial_end_epoch')->nullable();
            $table->unsignedBigInteger('billing_end_epoch')->nullable();
            $table->unsignedBigInteger('billing_current_period_end_epoch')->nullable();
            $table->unsignedBigInteger('billing_created_epoch')->nullable();
            $table->boolean('billing_cancel_at_period_end')->nullable();
            $table->foreignId('billing_projection_attempt_id')->nullable()->constrained('billing_reconciliation_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve canonical subscription instants and audit bindings; use a compatible application rollback.');
    }
};
