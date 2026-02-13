<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_elo_ratings', function (Blueprint $table) {
            $table->decimal('elo_change', 10, 1)->nullable()->after('elo_rating');
        });
    }

    public function down(): void
    {
        Schema::table('cfb_elo_ratings', function (Blueprint $table) {
            $table->dropColumn('elo_change');
        });
    }
};
