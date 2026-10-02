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
        Schema::table('nflverse_pbp_plays', function (Blueprint $table) {
            $table->string('ftn_read_thrown', 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table) {
            $table->dropColumn('ftn_read_thrown');
        });
    }
};
