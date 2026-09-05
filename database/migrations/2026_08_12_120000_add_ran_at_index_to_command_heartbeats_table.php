<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_heartbeats', function (Blueprint $table) {
            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::table('command_heartbeats', function (Blueprint $table) {
            $table->dropIndex(['ran_at']);
        });
    }
};
