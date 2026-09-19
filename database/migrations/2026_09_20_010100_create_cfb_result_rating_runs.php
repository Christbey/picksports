<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_result_rating_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('season');
            $table->string('version');
            $table->string('input_hash', 64)->unique();
            $table->json('source_rows');
            $table->json('model');
            $table->timestamp('source_available_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_result_rating_runs');
    }
};
