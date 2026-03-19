<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_predictions', function (Blueprint $table) {
            $table->string('ats_pick_side', 10)->nullable()->after('winner_correct');
            $table->string('ats_pick_result', 10)->nullable()->after('ats_pick_side');
            $table->decimal('ats_pick_edge', 5, 1)->nullable()->after('ats_pick_result');
        });
    }

    public function down(): void
    {
        Schema::table('cbb_predictions', function (Blueprint $table) {
            $table->dropColumn([
                'ats_pick_side',
                'ats_pick_result',
                'ats_pick_edge',
            ]);
        });
    }
};
