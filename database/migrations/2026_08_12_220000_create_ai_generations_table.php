<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('purpose', 64);
            $table->string('context_type', 64)->nullable();
            $table->string('context_id', 64)->nullable();
            $table->string('prompt_version', 100);
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->string('status', 20);
            $table->char('input_hash', 64);
            $table->char('output_hash', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'created_at']);
            $table->index(['context_type', 'context_id']);
            $table->index(['provider', 'model', 'created_at'], 'ai_generations_provider_model_created_idx');
            $table->index(['status', 'created_at']);
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
