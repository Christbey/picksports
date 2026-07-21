<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nflverse_depth_charts', function (Blueprint $table) {
            if (! Schema::hasColumn('nflverse_depth_charts', 'source_updated_at')) {
                $table->timestamp('source_updated_at')->nullable()->after('depth_rank');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nflverse_depth_charts', function (Blueprint $table) {
            if (Schema::hasColumn('nflverse_depth_charts', 'source_updated_at')) {
                $table->dropColumn('source_updated_at');
            }
        });
    }
};
