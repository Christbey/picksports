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
        Schema::table('cfb_predictions', function (Blueprint $table) {
            $table->decimal('predicted_total', 5, 1)->nullable()->after('predicted_spread');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cfb_predictions', function (Blueprint $table) {
            $table->dropColumn('predicted_total');
        });
    }
};
