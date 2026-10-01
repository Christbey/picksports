<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            foreach (['first_down', 'shotgun', 'qb_scramble', 'pass_touchdown', 'complete_pass', 'qb_hit'] as $field) {
                $table->boolean($field)->nullable();
            }
            foreach (['air_yards', 'cpoe', 'pass_oe'] as $field) {
                $table->decimal($field, 10, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->dropColumn(['first_down', 'shotgun', 'qb_scramble', 'pass_touchdown', 'complete_pass', 'qb_hit', 'air_yards', 'cpoe', 'pass_oe']);
        });
    }
};
