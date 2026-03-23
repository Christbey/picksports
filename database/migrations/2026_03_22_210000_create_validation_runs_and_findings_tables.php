<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command_name', 120);
            $table->string('scope', 80)->nullable();
            $table->string('status', 20)->default('running');
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('validation_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('validation_run_id')->constrained('validation_runs')->cascadeOnDelete();
            $table->string('sport', 20);
            $table->string('check_type', 120);
            $table->string('scope_type', 40)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('status', 20);
            $table->string('severity', 20)->nullable();
            $table->text('message');
            $table->json('facts')->nullable();
            $table->string('recommended_action', 255)->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['sport', 'check_type']);
            $table->index(['status', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_findings');
        Schema::dropIfExists('validation_runs');
    }
};
