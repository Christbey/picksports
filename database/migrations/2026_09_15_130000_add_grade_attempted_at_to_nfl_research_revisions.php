<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_research_revisions', function (Blueprint $table) {
            $table->timestamp('grade_attempted_at')->nullable();
            $table->index(
                ['graded_at', 'grade_attempted_at', 'id'],
                'nfl_research_grade_rotation_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('nfl_research_revisions', function (Blueprint $table) {
            $table->dropIndex('nfl_research_grade_rotation_idx');
            $table->dropColumn('grade_attempted_at');
        });
    }
};
