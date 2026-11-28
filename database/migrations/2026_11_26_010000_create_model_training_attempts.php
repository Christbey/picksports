<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_training_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('cycle_run_id')->constrained('model_runs')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->uuid('owner_token')->unique();
            $table->uuid('python_run_id')->unique();
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('lease_expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->char('output_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->unique(['cycle_run_id', 'attempt_number']);
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve model training attempt history; roll back application code without dropping this additive table.');
    }
};
