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
            DB::statement('ALTER TABLE nfl_research_revisions ADD COLUMN payload_archive JSON NULL, ALGORITHM=INSTANT');

            return;
        }

        Schema::table('nfl_research_revisions', function (Blueprint $table): void {
            $table->json('payload_archive')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('nfl_research_revisions')->whereNotNull('payload_archive')->exists()) {
            throw new RuntimeException('Restore the archived research revisions before removing their references.');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE nfl_research_revisions DROP COLUMN payload_archive, ALGORITHM=INSTANT');

            return;
        }

        Schema::table('nfl_research_revisions', function (Blueprint $table): void {
            $table->dropColumn('payload_archive');
        });
    }
};
