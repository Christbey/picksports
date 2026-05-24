<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wnba_players')) {
            return;
        }

        Schema::table('wnba_players', function (Blueprint $table): void {
            if (! Schema::hasColumn('wnba_players', 'age')) {
                $table->unsignedSmallInteger('age')->nullable()->after('weight');
            }

            if (! Schema::hasColumn('wnba_players', 'experience')) {
                $table->unsignedSmallInteger('experience')->nullable()->after('age');
            }

            if (! Schema::hasColumn('wnba_players', 'college')) {
                $table->string('college')->nullable()->after('experience');
            }

            if (! Schema::hasColumn('wnba_players', 'status')) {
                $table->string('status', 50)->nullable()->after('college');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wnba_players')) {
            return;
        }

        Schema::table('wnba_players', function (Blueprint $table): void {
            $drops = [];

            foreach (['age', 'experience', 'college', 'status'] as $column) {
                if (Schema::hasColumn('wnba_players', $column)) {
                    $drops[] = $column;
                }
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
