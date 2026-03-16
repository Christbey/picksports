<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_fpi_ratings', function (Blueprint $table) {
            if (! Schema::hasColumn('cfb_fpi_ratings', 'fpi_rank')) {
                $table->unsignedInteger('fpi_rank')->nullable()->after('fpi');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cfb_fpi_ratings', function (Blueprint $table) {
            if (Schema::hasColumn('cfb_fpi_ratings', 'fpi_rank')) {
                $table->dropColumn('fpi_rank');
            }
        });
    }
};
