<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_availability_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('cfb_games')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('cfb_teams')->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('source_url', 512);
            $table->string('report_key', 64);
            $table->string('payload_hash', 64);
            $table->string('state', 20);
            $table->timestamp('reported_at');
            $table->timestamp('observed_at');
            $table->json('entries');
            $table->json('issues');
            $table->timestamps();
            $table->unique(['game_id', 'team_id', 'source', 'report_key', 'payload_hash'], 'cfb_availability_revision_unique');
            $table->index(['game_id', 'team_id', 'reported_at'], 'cfb_availability_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_availability_reports');
    }
};
