<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_training_worker_health', function (Blueprint $table): void {
            $table->foreignUuid('worker_id')->primary()->constrained('model_training_workers')->restrictOnDelete();
            $table->timestamp('last_seen_at');
            $table->json('metrics');
            $table->timestamps();
        });
        Schema::create('model_training_progress', function (Blueprint $table): void {
            $table->foreignId('attempt_id')->primary()->constrained('model_training_attempts')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('stage', 40);
            $table->text('log_tail');
            $table->timestamp('reported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain training monitoring evidence; roll back application code without dropping these additive tables.');
    }
};
