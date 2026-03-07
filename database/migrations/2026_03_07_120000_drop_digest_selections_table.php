<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('digest_selections');
    }

    public function down(): void
    {
        Schema::create('digest_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_template_id')->constrained('notification_templates')->cascadeOnDelete();
            $table->string('sport', 10);
            $table->date('target_date');
            $table->enum('selection_type', ['prediction', 'player_prop']);
            $table->unsignedBigInteger('selection_id');
            $table->string('selection_model');
            $table->unsignedBigInteger('game_id')->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['notification_template_id', 'sport', 'target_date', 'selection_type', 'selection_id', 'selection_model'],
                'digest_selection_unique'
            );
            $table->index(['notification_template_id', 'sport', 'target_date'], 'digest_selection_lookup');
        });
    }
};
