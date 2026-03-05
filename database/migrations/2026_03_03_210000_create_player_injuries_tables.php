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
        $this->createInjuriesTable('nba');
        $this->createInjuriesTable('wnba');
        $this->createInjuriesTable('nfl');
        $this->createInjuriesTable('cfb');
        $this->createInjuriesTable('cbb');
        $this->createInjuriesTable('wcbb');
        $this->createInjuriesTable('mlb');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_player_injuries');
        Schema::dropIfExists('wcbb_player_injuries');
        Schema::dropIfExists('cbb_player_injuries');
        Schema::dropIfExists('cfb_player_injuries');
        Schema::dropIfExists('nfl_player_injuries');
        Schema::dropIfExists('wnba_player_injuries');
        Schema::dropIfExists('nba_player_injuries');
    }

    private function createInjuriesTable(string $sport): void
    {
        Schema::create("{$sport}_player_injuries", function (Blueprint $table) use ($sport) {
            $table->id();
            $table->foreignId('player_id')->constrained("{$sport}_players")->onDelete('cascade');
            $table->foreignId('team_id')->constrained("{$sport}_teams")->onDelete('cascade');
            $table->string('injury_key', 120);
            $table->string('espn_injury_id', 100)->nullable();
            $table->string('status', 100)->nullable();
            $table->string('detail', 255)->nullable();
            $table->string('type', 100)->nullable();
            $table->date('return_date')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'injury_key']);
            $table->index(['team_id', 'is_active']);
            $table->index('status');
        });
    }
};
