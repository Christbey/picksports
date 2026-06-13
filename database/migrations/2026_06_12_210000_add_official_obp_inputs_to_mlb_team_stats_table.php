<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_team_stats', function (Blueprint $table): void {
            if (! Schema::hasColumn('mlb_team_stats', 'hit_by_pitch')) {
                $table->unsignedSmallInteger('hit_by_pitch')->nullable()->after('walks');
            }

            if (! Schema::hasColumn('mlb_team_stats', 'sacrifice_flies')) {
                $table->unsignedSmallInteger('sacrifice_flies')->nullable()->after('hit_by_pitch');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mlb_team_stats', function (Blueprint $table): void {
            $drops = [];

            if (Schema::hasColumn('mlb_team_stats', 'hit_by_pitch')) {
                $drops[] = 'hit_by_pitch';
            }

            if (Schema::hasColumn('mlb_team_stats', 'sacrifice_flies')) {
                $drops[] = 'sacrifice_flies';
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
