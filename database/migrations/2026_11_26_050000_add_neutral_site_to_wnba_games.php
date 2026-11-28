<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wnba_games', function (Blueprint $table): void {
            $table->boolean('neutral_site')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain observed neutral-site facts; deploy compatible code or a forward migration.');
    }
};
