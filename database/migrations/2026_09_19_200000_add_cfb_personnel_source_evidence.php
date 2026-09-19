<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_preseason_team_signals', function (Blueprint $table): void {
            $table->json('source_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cfb_preseason_team_signals', fn (Blueprint $table) => $table->dropColumn('source_evidence'));
    }
};
