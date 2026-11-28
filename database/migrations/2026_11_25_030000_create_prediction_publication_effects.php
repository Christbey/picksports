<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_publication_effects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('canonical_prediction_id')->constrained('predictions')->restrictOnDelete();
            $table->string('effect', 32);
            $table->json('payload')->nullable();
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('owner_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('reason_code', 100)->nullable();
            $table->timestamps();
            $table->unique(['canonical_prediction_id', 'effect'], 'publication_effect_identity_unique');
            $table->index(['state', 'next_attempt_at', 'id'], 'publication_effect_due_idx');
            $table->index(['state', 'lease_expires_at', 'id'], 'publication_effect_lease_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain publication delivery intents and receipts; quiesce/reconcile before rolling back compatible application code.');
    }
};
