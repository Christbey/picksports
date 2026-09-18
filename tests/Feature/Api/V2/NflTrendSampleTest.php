<?php

use App\Actions\NFL\CalculateTeamTrends;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Cache::flush();
    Sanctum::actingAs(User::factory()->create());
});

it('defaults NFL trends to regular season and reports actual rather than requested samples', function () {
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();

    foreach (['1', '2', '3'] as $phase) {
        Game::factory()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'season_type' => $phase,
            'status' => 'STATUS_FINAL',
            'game_date' => '2026-09-13',
            'home_score' => 28,
            'away_score' => 14,
        ]);
    }

    expect(app(CalculateTeamTrends::class)->countAvailableGames($team, 2026))->toBe(1);

    foreach (['season', '20'] as $sample) {
        $this->getJson("/api/v2/sports/nfl/teams/{$team->id}/trends?season=2026&games={$sample}")
            ->assertOk()
            ->assertJsonPath('data.sample_size', 1);
    }

    $this->getJson("/api/v2/sports/nfl/teams/{$team->id}/trends?season=2026&games=season&season_type=1")
        ->assertOk()
        ->assertJsonPath('data.sample_size', 1);
});

it('reports zero available NFL games without inventing a one game season sample', function () {
    $team = Team::factory()->create();

    foreach (['season', '20'] as $sample) {
        $this->getJson("/api/v2/sports/nfl/teams/{$team->id}/trends?season=2026&games={$sample}")
            ->assertOk()
            ->assertJsonPath('data.sample_size', 0)
            ->assertJsonPath('data.trends', [])
            ->assertJsonPath('data.scored_signals', []);
    }
});

it('excludes current and later games using the UTC kickoff cutoff and caches identical requests', function () {
    $team = Team::factory()->create();
    $opponent = Team::factory()->create();

    foreach (['00:00:00', '00:15:00', '12:00:00'] as $time) {
        Game::factory()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'season_type' => '2',
            'status' => 'STATUS_FINAL',
            'game_date' => '2026-09-18',
            'game_time' => $time,
        ]);
    }

    $url = "/api/v2/sports/nfl/teams/{$team->id}/trends?season=2026&games=season&before_date=2026-09-18T00%3A15%3A00Z";
    $first = $this->getJson($url)->assertOk()->assertJsonPath('data.sample_size', 1);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $second = $this->getJson($url)->assertOk();
        expect($second->json('data'))->toEqual($first->json('data'));
        expect(collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'nfl_games')
        ))->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
    }
});
