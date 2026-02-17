<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wnba_teams', function (Blueprint $table) {
            $table->renameColumn('school', 'location');
            $table->renameColumn('mascot', 'name');
        });
    }

    public function down(): void
    {
        Schema::table('wnba_teams', function (Blueprint $table) {
            $table->renameColumn('location', 'school');
            $table->renameColumn('name', 'mascot');
        });
    }
};
