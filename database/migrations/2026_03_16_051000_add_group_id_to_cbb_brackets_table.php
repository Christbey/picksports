<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_brackets', function (Blueprint $table): void {
            $table->foreignId('group_id')->nullable()->after('user_id')->constrained('groups')->nullOnDelete();
            $table->index(['group_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::table('cbb_brackets', function (Blueprint $table): void {
            $table->dropIndex(['group_id', 'season']);
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
