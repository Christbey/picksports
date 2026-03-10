<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_digest_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('digest_date');
            $table->timestamp('sent_at');
            $table->unsignedTinyInteger('predictions_count')->default(0);
            $table->unsignedTinyInteger('player_props_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'digest_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_digest_sends');
    }
};
