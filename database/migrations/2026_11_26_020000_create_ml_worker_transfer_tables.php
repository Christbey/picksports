<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_training_workers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->json('sports');
            $table->json('abilities');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('model_training_transfer_budgets', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('model_training_transfer_budgets')->insert(['id' => 1]);
        Schema::create('model_training_transfer_slots', function (Blueprint $table): void {
            $table->foreignUuid('worker_id')->primary()->constrained('model_training_workers')->restrictOnDelete();
            $table->foreignId('attempt_id')->constrained('model_training_attempts')->restrictOnDelete();
            $table->uuid('owner_token');
            $table->unsignedBigInteger('reserved_bytes');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('model_training_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attempt_id')->constrained('model_training_attempts')->restrictOnDelete();
            $table->string('path', 191);
            $table->string('disk', 64);
            $table->string('object_key', 512);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
            $table->unique(['attempt_id', 'path']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain worker and transfer audit records; roll back application code without dropping these additive tables.');
    }
};
