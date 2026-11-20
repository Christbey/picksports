<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('group_join_links')->select('group_id')->groupBy('group_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot enforce one join link per group: duplicate group links require explicit review. No links have been deleted.');
        }

        Schema::table('group_join_links', function (Blueprint $table): void {
            $table->unique('group_id', 'group_join_links_group_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('group_join_links', function (Blueprint $table): void {
            $table->dropUnique('group_join_links_group_id_unique');
        });
    }
};
