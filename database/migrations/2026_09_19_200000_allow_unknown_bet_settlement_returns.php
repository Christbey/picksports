<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bet_settlements', fn (Blueprint $table) => $table->decimal('profit_units', 10, 4)->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('bet_settlements')->whereNull('profit_units')->exists()) {
            throw new RuntimeException('Cannot discard unknown settlement returns during rollback.');
        }
        Schema::table('bet_settlements', fn (Blueprint $table) => $table->decimal('profit_units', 10, 4)->nullable(false)->change());
    }
};
