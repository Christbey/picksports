<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bets', function (Blueprint $table) {
            $table->string('selection_side', 20)->nullable()->after('bet_type');
            $table->string('selection_label')->nullable()->after('selection_side');
            $table->decimal('line', 8, 2)->nullable()->after('selection_label');
        });
    }

    public function down(): void
    {
        Schema::table('user_bets', function (Blueprint $table) {
            $table->dropColumn(['selection_side', 'selection_label', 'line']);
        });
    }
};
