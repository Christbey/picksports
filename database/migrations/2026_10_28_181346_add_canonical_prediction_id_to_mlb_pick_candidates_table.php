<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_pick_candidates', function (Blueprint $table): void {
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mlb_pick_candidates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canonical_prediction_id');
        });
    }
};
