<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_bet_filter_results', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_id')->nullable()->change();
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->restrictOnDelete();
            $table->unique(['canonical_prediction_id', 'market', 'pick_side', 'as_of_date'], 'mlb_bet_filter_native_snapshot');
        });
    }

    public function down(): void
    {
        if (DB::table('mlb_bet_filter_results')->whereNotNull('canonical_prediction_id')->exists()) {
            throw new LogicException('Preserve native bet-filter lineage before rolling back this migration.');
        }
        Schema::table('mlb_bet_filter_results', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_id')->nullable(false)->change();
            $table->dropUnique('mlb_bet_filter_native_snapshot');
            $table->dropConstrainedForeignId('canonical_prediction_id');
        });
    }
};
