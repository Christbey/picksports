<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_games', fn (Blueprint $table) => $table->json('overtime_state')->nullable());
    }

    public function down(): void
    {
        Schema::table('cfb_games', fn (Blueprint $table) => $table->dropColumn('overtime_state'));
    }
};
