<?php

use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Prediction as NbaPrediction;
use App\Models\NBA\Team as NbaTeam;
use App\Models\User;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

it('requires authenticated access for the v2 live scoreboard endpoint', function () {
    $this->getJson('/api/v2/live-scoreboard')
        ->assertUnauthorized();
});

it('returns v2 live scoreboard games with stable metadata', function () {
    $user = User::factory()->create();
    config()->set('subscriptions.enforce_tiers', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);
    app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_LIVE_SCOREBOARD);

    $homeTeam = NbaTeam::factory()->create(['abbreviation' => 'BOS']);
    $awayTeam = NbaTeam::factory()->create(['abbreviation' => 'NYK']);
    $game = NbaGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'season' => 2026,
        'game_date' => now()->toDateString(),
        'game_time' => '12:00:00',
        'status' => 'STATUS_IN_PROGRESS',
        'period' => 2,
        'game_clock' => '08:41',
        'home_score' => 54,
        'away_score' => 49,
    ]);
    DB::table('nba_games')
        ->where('id', $game->id)
        ->update([
            'game_date' => now()->toDateString(),
            'game_time' => '12:00:00',
        ]);
    $prediction = NbaPrediction::query()->create(liveScoreboardPredictionAttributes(NbaPrediction::class, $game->id));

    $this->getJson('/api/v2/live-scoreboard')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'games' => [
                    '*' => [
                        'id',
                        'sport',
                        'game_id',
                        'game',
                        'game_time',
                        'home_team',
                        'away_team',
                        'is_live',
                        'is_final',
                        'home_score',
                        'away_score',
                        'period',
                        'game_clock',
                    ],
                ],
                'updated_at',
            ],
            'meta' => [
                'version',
                'contract',
                'tier',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('data.games.0.id', $prediction->id)
        ->assertJsonPath('data.games.0.sport', 'NBA')
        ->assertJsonPath('data.games.0.game_id', $game->id)
        ->assertJsonPath('data.games.0.home_team', 'BOS')
        ->assertJsonPath('data.games.0.away_team', 'NYK')
        ->assertJsonPath('data.games.0.is_live', true)
        ->assertJsonPath('data.games.0.home_score', 54)
        ->assertJsonPath('data.games.0.away_score', 49)
        ->assertJsonPath('data.games.0.period', 2)
        ->assertJsonPath('data.games.0.game_clock', '08:41')
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.contract', 'live-scoreboard.show');
});

/**
 * @param  class-string<Model>  $predictionModel
 * @return array<string, mixed>
 */
function liveScoreboardPredictionAttributes(string $predictionModel, int $gameId): array
{
    $columns = array_flip(Schema::getColumnListing((new $predictionModel)->getTable()));

    return array_intersect_key([
        'game_id' => $gameId,
        'season' => 2026,
        'season_type' => '2',
        'home_elo' => 1510.5,
        'away_elo' => 1488.5,
        'predicted_spread' => -3.5,
        'predicted_total' => 217.5,
        'win_probability' => 0.642,
        'confidence_score' => 71.25,
        'live_win_probability' => 0.61,
        'live_predicted_spread' => -2.5,
        'live_predicted_total' => 221.0,
        'live_seconds_remaining' => 1721,
        'model_version' => 'v2-live-scoreboard-test',
    ], $columns);
}
