<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('age_verified_at')->nullable()->after('email_verified_at');
        });

        DB::table('users')
            ->whereNull('age_verified_at')
            ->update([
                'age_verified_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('age_verified_at');
        });
    }
};
