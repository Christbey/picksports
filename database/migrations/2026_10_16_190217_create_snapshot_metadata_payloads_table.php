<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_metadata_payloads', function (Blueprint $table): void {
            $table->char('sha256', 64)->primary();
            $table->char('compressed_sha256', 64);
            $table->unsignedInteger('json_bytes');
            $table->unsignedInteger('compressed_bytes');
            $table->longText('payload');
        });
    }

    public function down(): void
    {
        if (DB::table('snapshot_metadata_payloads')->exists()) {
            throw new RuntimeException('Restore snapshot metadata and verify payload cleanup before rolling back this migration.');
        }

        Schema::dropIfExists('snapshot_metadata_payloads');
    }
};
