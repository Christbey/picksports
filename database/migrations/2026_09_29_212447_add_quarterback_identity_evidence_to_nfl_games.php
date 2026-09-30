<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_games', function (Blueprint $table) {
            $table->json('quarterback_identity_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfl_games', function (Blueprint $table) {
            $table->dropColumn('quarterback_identity_evidence');
        });
    }
};
