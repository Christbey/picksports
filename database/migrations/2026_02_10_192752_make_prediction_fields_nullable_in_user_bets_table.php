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
        Schema::table('user_bets', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_id')->nullable()->change();
            $table->string('prediction_type')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_bets', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_id')->nullable(false)->change();
            $table->string('prediction_type')->nullable(false)->change();
        });
    }
};
