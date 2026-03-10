<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_bets', 'publics_side')) {
            return;
        }

        Schema::table('user_bets', function (Blueprint $table) {
            $table->dropColumn('publics_side');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_bets', 'publics_side')) {
            return;
        }

        Schema::table('user_bets', function (Blueprint $table) {
            $table->string('publics_side', 20)->nullable()->after('line');
        });
    }
};
