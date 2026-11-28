<?php

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TournamentForecast;
use App\Models\CBB\TournamentStateSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('requires complete final scores before eliminating a stored CBB forecast', function (?int $homeScore, ?int $awayScore, string $status, ?string $loser) {
    Cache::flush();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);

    $home = Team::factory()->create(['abbreviation' => 'HOME']);
    $away = Team::factory()->create(['abbreviation' => 'AWAY']);
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => config('cbb.season.types.postseason'),
        'game_date' => '2026-03-20',
        'game_time' => '18:00:00',
        'is_ncaa_tournament' => true,
        'tournament_round' => 'round_of_64',
        'status' => $status,
        'home_score' => $homeScore,
        'away_score' => $awayScore,
    ]);
    $probabilities = [
        $home->id => ['champion_probability' => 0.12, 'final_four_probability' => 0.36, 'title_game_probability' => 0.20],
        $away->id => ['champion_probability' => 0.08, 'final_four_probability' => 0.24, 'title_game_probability' => 0.16],
    ];
    foreach ([$home, $away] as $team) {
        TournamentForecast::factory()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'snapshot_id' => null,
            'mode' => 'baseline',
            'is_eliminated' => false,
            'is_alive' => true,
            ...$probabilities[$team->id],
        ]);
    }
    expect(TournamentStateSnapshot::query()->where('season', 2026)->where('status', 'completed')->exists())->toBeFalse();
    $storedGame = (array) DB::table('cbb_games')->where('id', $game->id)->first();
    foreach (['home_score' => $homeScore, 'away_score' => $awayScore] as $column => $value) {
        if ($value === null) {
            expect($storedGame[$column])->toBeNull();
        } else {
            expect($storedGame[$column])->not->toBeNull();
            expect((int) $storedGame[$column])->toBe($value);
        }
    }
    $storedForecasts = DB::table('cbb_tournament_forecasts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    $response = $this->getJson('/api/v2/sports/cbb/forecasts?season=2026')
        ->assertOk()
        ->assertJsonPath('meta.sport', 'cbb')
        ->assertJsonPath('meta.mode', 'baseline')
        ->assertJsonPath('meta.snapshot_id', null)
        ->assertJsonPath('meta.actual_field_size', 2)
        ->assertJsonCount(2, 'data');
    $rows = collect($response->json('data'))->keyBy('team_id');
    foreach (['home' => $home, 'away' => $away] as $side => $team) {
        $row = $rows->get($team->id);
        expect($row)->toBeArray();
        expect($row['is_actual_field'])->toBeTrue();
        expect($row['is_eliminated'])->toBe($loser === $side);
        foreach ($probabilities[$team->id] as $field => $probability) {
            expect($row[$field])->not->toBeNull();
            expect((float) $row[$field])->toBe($loser === $side ? 0.0 : $probability);
        }
    }
    expect((array) DB::table('cbb_games')->where('id', $game->id)->first())->toBe($storedGame);
    expect(DB::table('cbb_tournament_forecasts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($storedForecasts);
    Http::assertNothingSent();
})->with([
    'unknown away score cannot establish a home win' => [80, null, 'STATUS_FINAL', null],
    'unknown home score cannot establish an away win' => [null, 75, 'STATUS_FINAL', null],
    'observed home zero is a genuine loss' => [0, 70, 'STATUS_FINAL', 'home'],
    'observed away zero is a genuine loss' => [70, 0, 'STATUS_FINAL', 'away'],
    'complete home win still eliminates away' => [80, 75, 'STATUS_FINAL', 'away'],
    'complete away win still eliminates home' => [75, 80, 'STATUS_FINAL', 'home'],
    'both scores unavailable withhold elimination' => [null, null, 'STATUS_FINAL', null],
    'nonfinal score lead does not eliminate a team' => [80, 75, 'STATUS_IN_PROGRESS', null],
]);
