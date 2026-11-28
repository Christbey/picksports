<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_run_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('calculation_run_id')->constrained('calculation_runs')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->uuid('owner_token')->unique();
            $table->string('status', 20);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->char('output_hash', 64)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->boolean('legacy_import')->default(false);
            $table->timestamps();
            $table->unique(['calculation_run_id', 'attempt_number'], 'calculation_attempts_run_number_unique');
            $table->index(['status', 'lease_expires_at'], 'calculation_attempts_lease_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve calculation attempt audit history; roll back application code without dropping this additive table.');
    }
};
