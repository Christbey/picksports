<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\Team;
use App\Models\User;
use App\Services\BettingRecommendations\NflPropAvailabilityContext;
use App\Services\BettingRecommendations\NflPropGameTime;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['sports.business_timezone' => 'America/Chicago']);
    $this->travelTo(Carbon::parse('2026-09-17 10:00:00', 'America/Chicago'));
    Sanctum::actingAs(User::factory()->create());
});

function nflPropTimeGame(string $date, ?string $time): Game
{
    $game = Game::factory()->create([
        'home_team_id' => (Team::where('abbreviation', 'BUF')->first() ?? Team::factory()->create(['abbreviation' => 'BUF']))->id,
        'away_team_id' => (Team::where('abbreviation', 'DET')->first() ?? Team::factory()->create(['abbreviation' => 'DET']))->id,
        'game_date' => $date,
        'game_time' => $time,
        'status' => 'STATUS_SCHEDULED',
    ]);
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'position' => 'QB']);
    $availability = app(NflPropAvailabilityContext::class)->resolve($game, $player->id, 'player_pass_yds');
    PlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Provider Quarterback',
        'market' => 'player_pass_yds',
        'bookmaker' => 'draftkings',
        'line' => 250.5,
        'over_price' => -110,
        'under_price' => -110,
        'recommended_side' => 'Over',
        'confidence_score' => 70,
        'confidence_decomposition' => [
            'schema_version' => 'player-prop-signal-v2',
            'stat_summary' => ['season_avg' => 270, 'recent_avg' => 270, 'last5_avg' => 270],
            'cover_record' => [],
            'availability' => $availability,
        ],
    ]);

    return $game;
}

it('uses the local kickoff date for NFL board defaults, filters, and recommendation cards', function () {
    $game = nflPropTimeGame('2026-09-18', '00:15:00');

    $this->getJson('/api/v2/sports/nfl/player-props/board')
        ->assertOk()
        ->assertJsonPath('filters.date', '2026-09-17')
        ->assertJsonPath('dates.0.value', '2026-09-17')
        ->assertJsonPath('games.0.id', $game->id)
        ->assertJsonPath('games.0.date', '2026-09-17')
        ->assertJsonPath('games.0.label', 'DET @ BUF - 7:15 PM CDT')
        ->assertJsonPath('games.0.starts_at', '2026-09-18T00:15:00+00:00')
        ->assertJsonPath('games.0.timezone', 'America/Chicago')
        ->assertJsonPath('data.0.game.kickoff_label', 'Thu, Sep 17, 7:15 PM CDT')
        ->assertJsonPath('data.0.game.starts_at', '2026-09-18T00:15:00+00:00')
        ->assertJsonPath('meta.diagnostics.raw_prop_count', 1);

    $this->getJson('/api/v2/sports/nfl/player-props/board?date=2026-09-17')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'games');
    $this->getJson('/api/v2/sports/nfl/player-props/board?date=2026-09-18')
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'games')
        ->assertJsonPath('meta.diagnostics.raw_prop_count', 0);
});

it('formats NFL kickoffs using the offset on game day rather than today', function (string $date, string $time, string $localDate, string $label) {
    nflPropTimeGame($date, $time);

    $games = app(PlayerPropAnalyzer::class)->getAvailableGamesForSport('NFL', $localDate);
    expect($games)->toHaveCount(1)
        ->and($games->first()['date'])->toBe($localDate)
        ->and($games->first()['label'])->toBe('DET @ BUF - '.$label);
})->with([
    'Sunday noon CDT' => ['2026-09-20', '17:00:00', '2026-09-20', '12:00 PM CDT'],
    'Sunday afternoon CDT' => ['2026-09-20', '20:25:00', '2026-09-20', '3:25 PM CDT'],
    'Monday night previous UTC day' => ['2026-09-22', '00:15:00', '2026-09-21', '7:15 PM CDT'],
    'winter CST while today is CDT' => ['2026-12-11', '01:15:00', '2026-12-10', '7:15 PM CST'],
    'spring transition before clock change' => ['2026-03-08', '07:30:00', '2026-03-08', '1:30 AM CST'],
    'spring transition after clock change' => ['2026-03-08', '08:30:00', '2026-03-08', '3:30 AM CDT'],
]);

it('does not invent an NFL kickoff when only the calendar date is known', function () {
    $schedule = app(NflPropGameTime::class)
        ->forGame(new Game(['game_date' => '2026-09-20', 'game_time' => null]));
    expect($schedule['date'])->toBe('2026-09-20')
        ->and($schedule['time_label'])->toBe('Time TBD')
        ->and($schedule['starts_at'])->toBeNull();
});

it('keeps the default NFL slate on the business date after UTC midnight', function () {
    $this->travelTo(Carbon::parse('2026-09-18 03:00:00', 'UTC'));
    nflPropTimeGame('2026-09-18', '00:15:00');
    nflPropTimeGame('2026-09-19', '00:15:00');

    $this->getJson('/api/v2/sports/nfl/player-props/board')
        ->assertOk()->assertJsonPath('filters.date', '2026-09-17');
});
