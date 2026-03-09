<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->nullableMorphs('team');
        });
    }

    public function down(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->dropMorphs('team');
        });
    }
};
