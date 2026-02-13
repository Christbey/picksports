<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_teams', function (Blueprint $table) {
            $table->integer('elo_rating')->default(1500)->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('cfb_teams', function (Blueprint $table) {
            $table->dropColumn('elo_rating');
        });
    }
};
