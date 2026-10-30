<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_period_feature_snapshots', function (Blueprint $table): void {
            $table->string('forecast_source', 16)->default('legacy');
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('mlb_period_feature_snapshots')->where('forecast_source', '!=', 'legacy')
            ->orWhereNotNull('canonical_prediction_id')->exists()) {
            throw new LogicException('Preserve native MLB period feature lineage before rollback.');
        }
        Schema::table('mlb_period_feature_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canonical_prediction_id');
            $table->dropColumn('forecast_source');
        });
    }
};
