<?php

use App\Models\NBA\Game;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createSportPropsTable('nba_player_props', 'nba_games');
        $this->createSportPropsTable('cbb_player_props', 'cbb_games');
        $this->createSportPropsTable('nfl_player_props', 'nfl_games');
        $this->createSportPropsTable('mlb_player_props', 'mlb_games');

        if (Schema::hasTable('player_props')) {
            $this->copyLegacyProps(
                fromSport: 'basketball_nba',
                fromGameableType: Game::class,
                fromGameTable: 'nba_games',
                toTable: 'nba_player_props'
            );
            $this->copyLegacyProps(
                fromSport: 'basketball_ncaab',
                fromGameableType: App\Models\CBB\Game::class,
                fromGameTable: 'cbb_games',
                toTable: 'cbb_player_props'
            );
            $this->copyLegacyProps(
                fromSport: 'americanfootball_nfl',
                fromGameableType: App\Models\NFL\Game::class,
                fromGameTable: 'nfl_games',
                toTable: 'nfl_player_props'
            );
            $this->copyLegacyProps(
                fromSport: 'baseball_mlb',
                fromGameableType: App\Models\MLB\Game::class,
                fromGameTable: 'mlb_games',
                toTable: 'mlb_player_props'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_player_props');
        Schema::dropIfExists('nfl_player_props');
        Schema::dropIfExists('cbb_player_props');
        Schema::dropIfExists('nba_player_props');
    }

    private function createSportPropsTable(string $table, string $gamesTable): void
    {
        Schema::create($table, function (Blueprint $table) use ($gamesTable) {
            $table->id();
            $table->foreignId('game_id')->constrained($gamesTable)->cascadeOnDelete();
            $table->unsignedBigInteger('player_id')->nullable()->index();
            $table->string('odds_api_event_id')->nullable()->index();
            $table->string('player_name');
            $table->string('market', 100)->index();
            $table->string('bookmaker', 50)->default('draftkings');
            $table->decimal('line', 8, 2)->nullable();
            $table->integer('over_price')->nullable();
            $table->integer('under_price')->nullable();
            $table->json('raw_data')->nullable();
            $table->decimal('actual_value', 8, 2)->nullable();
            $table->boolean('hit_over')->nullable();
            $table->decimal('error', 8, 2)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'market']);
            $table->index(['player_id', 'market']);
            $table->index(['fetched_at']);
            $table->index('graded_at');
        });
    }

    private function copyLegacyProps(string $fromSport, string $fromGameableType, string $fromGameTable, string $toTable): void
    {
        $rows = DB::table('player_props')
            ->where('sport', $fromSport)
            ->where('gameable_type', $fromGameableType)
            ->whereIn('gameable_id', DB::table($fromGameTable)->select('id'))
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $payload = $rows->map(function ($row) {
            return [
                'id' => $row->id,
                'game_id' => $row->gameable_id,
                'player_id' => $row->player_id,
                'odds_api_event_id' => $row->odds_api_event_id,
                'player_name' => $row->player_name,
                'market' => $row->market,
                'bookmaker' => $row->bookmaker,
                'line' => $row->line,
                'over_price' => $row->over_price,
                'under_price' => $row->under_price,
                'raw_data' => $row->raw_data,
                'actual_value' => $row->actual_value,
                'hit_over' => $row->hit_over,
                'error' => $row->error,
                'fetched_at' => $row->fetched_at,
                'graded_at' => $row->graded_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->all();

        DB::table($toTable)->insert($payload);
    }
};
