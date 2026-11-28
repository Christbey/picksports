<?php

use App\Actions\NBA\GeneratePlayoffForecast;
use App\Models\NBA\Game;
use App\Models\NBA\PlayoffForecast;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function postseasonCertaintyFixtureTeam(string $abbr, string $conference): Team
{
    $team = Team::factory()->create(['abbreviation' => $abbr, 'conference' => $conference,
        'division' => $conference === 'Eastern' ? 'Atlantic' : 'Southwest', 'elo_rating' => 1500]);
    TeamMetric::create(['team_id' => $team->id, 'season' => 2026, 'season_type' => '2', 'wins' => 10, 'losses' => 5,
        'offensive_efficiency' => 116.0, 'defensive_efficiency' => 110.0, 'net_rating' => 6.0, 'tempo' => 100.0,
        'strength_of_schedule' => 1500.0, 'calculation_date' => now()->toDateString()]);

    return $team;
}

it('requires stored postseason evidence before reducing the public NBA forecast pool', function (string $case, string $today, ?string $phase, array $dates, int $expectedCount) {
    Http::preventStrayRequests();
    $this->travelTo($today.' 12:00:00');
    try {
        $east = postseasonCertaintyFixtureTeam('NY', 'Eastern');
        $west = postseasonCertaintyFixtureTeam('SA', 'Western');
        $other = postseasonCertaintyFixtureTeam('OKC', 'Western');
        PlayoffForecast::create(['team_id' => $other->id, 'season' => 2026, 'conference' => 'Western', 'champion_probability' => 0.40]);
        foreach ($dates as $i => $date) {
            Game::factory()->create(['home_team_id' => $i === 0 ? $east->id : $west->id,
                'away_team_id' => $i === 0 ? $west->id : $east->id, 'season' => 2026, 'season_type' => $phase,
                'week' => 4, 'game_date' => $date, 'game_time' => '20:00:00', 'status' => 'STATUS_FINAL',
                'home_score' => 112, 'away_score' => 104]);
        }
        $tables = ['nba_games', 'nba_team_metrics', 'nba_playoff_forecasts'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $returned = app(GeneratePlayoffForecast::class)->execute(2026);
        $after = [];
        foreach ($tables as $table) {
            $after[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $trace = getenv('REVIEW_NBA_FINALS_TRACE');
        if (is_string($trace) && $trace !== '') {
            file_put_contents($trace, json_encode(['case' => $case,
                'before' => $before, 'after' => $after, 'returned_ids' => $returned->pluck('team_id')->all()], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        expect($after['nba_games'])->toBe($before['nba_games']);
        expect($after['nba_team_metrics'])->toBe($before['nba_team_metrics']);
        expect($returned)->toHaveCount($expectedCount);
        expect($after['nba_playoff_forecasts'])->toHaveCount($expectedCount);
        $ids = $returned->pluck('team_id')->all();
        if ($expectedCount === 3) {
            expect($ids)->toContain($other->id);
        } else {
            expect($ids)->not->toContain($other->id);
        }
    } finally {
        $this->travelBack();
    }
})->with([
    'no recent pair retains ordinary pool' => ['absent', '2026-11-20', null, [], 3],
    'November regular games retain ordinary pool' => ['regular-November', '2026-11-20', '2', ['2026-11-18', '2026-11-20'], 3],
    'stored postseason June pair retains existing shortcut' => ['postseason-June', '2026-06-06', '3', ['2026-06-04', '2026-06-06'], 2],
    'reviewed migration June regular phase retains ordinary pool' => ['regular-June-policy-migration', '2026-06-06', '2', ['2026-06-04', '2026-06-06'], 3],
    'unknown stored phase retains ordinary pool' => ['unknown-June', '2026-06-06', 'unknown', ['2026-06-04', '2026-06-06'], 3],
    'preseason stored phase retains ordinary pool' => ['preseason-June', '2026-06-06', '1', ['2026-06-04', '2026-06-06'], 3],
    'postseason admission does not invent a month heuristic' => ['postseason-November', '2026-11-20', '3', ['2026-11-18', '2026-11-20'], 2],
]);
