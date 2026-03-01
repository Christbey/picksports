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
        Schema::create('command_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 20)->nullable();
            $table->string('command', 255);
            $table->string('status', 20); // success, failure
            $table->string('source', 20)->default('schedule'); // schedule, manual
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['sport', 'ran_at']);
            $table->index(['status', 'ran_at']);
            $table->index(['command', 'ran_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('command_heartbeats');
    }
};
