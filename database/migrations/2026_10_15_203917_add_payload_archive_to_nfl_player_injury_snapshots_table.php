<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE nfl_player_injury_snapshots ADD COLUMN payload_archive JSON NULL, ALGORITHM=INSTANT');

            return;
        }

        Schema::table('nfl_player_injury_snapshots', function (Blueprint $table) {
            $table->json('payload_archive')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('nfl_player_injury_snapshots')->whereNull('raw_payload')->whereNotNull('payload_archive')->exists()) {
            throw new RuntimeException('Restore inline injury payloads before removing archive references.');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE nfl_player_injury_snapshots DROP COLUMN payload_archive, ALGORITHM=INSTANT');

            return;
        }

        Schema::table('nfl_player_injury_snapshots', function (Blueprint $table) {
            $table->dropColumn('payload_archive');
        });
    }
};
