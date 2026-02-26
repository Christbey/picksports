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
        Schema::table('player_props', function (Blueprint $table) {
            $table->decimal('actual_value', 8, 2)->nullable()->after('raw_data');
            $table->boolean('hit_over')->nullable()->after('actual_value');
            $table->decimal('error', 8, 2)->nullable()->after('hit_over');
            $table->timestamp('graded_at')->nullable()->after('error');

            $table->index('graded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_props', function (Blueprint $table) {
            $table->dropColumn(['actual_value', 'hit_over', 'error', 'graded_at']);
        });
    }
};
