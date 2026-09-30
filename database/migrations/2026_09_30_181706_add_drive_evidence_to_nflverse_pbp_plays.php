<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->unsignedSmallInteger('fixed_drive')->nullable();
            $table->string('fixed_drive_result')->nullable();
            $table->unsignedSmallInteger('possession_score_before')->nullable();
            $table->unsignedSmallInteger('possession_score_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->dropColumn(['fixed_drive', 'fixed_drive_result', 'possession_score_before', 'possession_score_after']);
        });
    }
};
