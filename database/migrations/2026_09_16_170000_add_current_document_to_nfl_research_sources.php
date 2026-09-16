<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_research_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('current_document_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfl_research_sources', function (Blueprint $table) {
            $table->dropColumn('current_document_id');
        });
    }
};
