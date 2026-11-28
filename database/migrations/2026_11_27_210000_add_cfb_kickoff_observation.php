<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->getConnection())->table('cfb_games', function (Blueprint $table): void {
            $table->json('kickoff_observation')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('CFB kickoff observations are forward-only source evidence.');
    }
};
