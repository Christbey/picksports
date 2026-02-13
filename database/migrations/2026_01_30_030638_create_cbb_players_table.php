<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cbb_players', function (Blueprint $table) {
            $table->id();
            $table->string('espn_id', 50)->unique();
            $table->foreignId('team_id')->nullable()->constrained('cbb_teams')->onDelete('set null');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('full_name', 200)->nullable();
            $table->string('jersey_number', 10)->nullable();
            $table->string('position', 10)->nullable();
            $table->string('height', 10)->nullable();
            $table->integer('weight')->nullable();
            $table->string('year', 20)->nullable();
            $table->string('hometown', 100)->nullable();
            $table->string('headshot_url')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('position');
            $table->index('last_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cbb_players');
    }
};
