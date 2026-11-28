<?php

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamPossessionMetric;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['cbb.season.default' => 2026]);
});

function possessionSelectorFixture(): array
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-02-01', 'status' => 'STATUS_FINAL', 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    Play::factory()->create(['game_id' => $game->id, 'possession_team_id' => $home->id, 'sequence_number' => 1, 'play_text' => 'Home layup good', 'home_score' => 2, 'away_score' => 0]);
    Play::factory()->create(['game_id' => $game->id, 'possession_team_id' => $away->id, 'sequence_number' => 2, 'play_text' => 'Away turnover', 'is_turnover' => true, 'home_score' => 2, 'away_score' => 0]);
    TeamPossessionMetric::query()->create(['team_id' => $home->id, 'season' => 2026, 'as_of_date' => '2026-01-01', 'games_sampled' => 99, 'metadata' => ['retained' => 'original-observation']]);

    return [$game, $home];
}

it('refuses malformed explicit possession selectors before rebuilding stored metrics', function (array $input) {
    possessionSelectorFixture();
    $before = DB::table('cbb_team_possession_metrics')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $exit = Artisan::call('cbb:calculate-possession-metrics', ['--season' => '2026', '--rebuild' => true, ...$input]);
    $after = DB::table('cbb_team_possession_metrics')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    expect($after)->toBe($before);
    expect($exit)->not->toBe(0);
})->with([
    'game junk' => [['--game_id' => 'junk']],
    'game zero' => [['--game_id' => '0']],
    'game negative' => [['--game_id' => '-1']],
    'game fractional' => [['--game_id' => '1.5']],
    'game explicit empty' => [['--game_id' => '']],
    'game explicit null' => [['--game_id' => null]],
    'season junk suffix' => [['--season' => '2026junk']],
    'season zero' => [['--season' => '0']],
    'season negative' => [['--season' => '-1']],
    'season explicit empty' => [['--season' => '']],
    'season explicit null' => [['--season' => null]],
    'limit junk' => [['--limit-games' => 'junk']],
    'limit fractional' => [['--limit-games' => '1.5']],
    'limit negative' => [['--limit-games' => '-1']],
    'limit explicit empty' => [['--limit-games' => '']],
    'limit explicit null' => [['--limit-games' => null]],
]);

it('preserves valid omitted and narrow possession scopes and intentional rebuild behavior', function (string $mode) {
    [$game] = possessionSelectorFixture();
    $options = [];
    if ($mode !== 'default season') {
        $options['--season'] = '2026';
    }
    if ($mode === 'narrow game') {
        $options['--game_id'] = (string) $game->id;
    }
    if ($mode === 'rebuild') {
        $options['--rebuild'] = true;
    }
    expect(Artisan::call('cbb:calculate-possession-metrics', $options))->toBe(0);
    expect(TeamPossessionMetric::query()->where('season', 2026)->where('games_sampled', 1)->count())->toBe(2);
    expect(TeamPossessionMetric::query()->where('games_sampled', 99)->count())->toBe($mode === 'rebuild' ? 0 : 1);
})->with(['default season', 'unfiltered', 'narrow game', 'rebuild']);

it('allows a valid empty game scope without rebuild to leave existing rows unchanged', function () {
    possessionSelectorFixture();
    $before = DB::table('cbb_team_possession_metrics')->get()->map(fn ($row) => (array) $row)->all();
    expect(Artisan::call('cbb:calculate-possession-metrics', ['--season' => '2026', '--game_id' => '2147483647']))->toBe(0);
    expect(DB::table('cbb_team_possession_metrics')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
});

it('retains the documented zero all-games and positive game limit scopes', function (string $limit, int $expected) {
    possessionSelectorFixture();
    possessionSelectorFixture();
    expect(Artisan::call('cbb:calculate-possession-metrics', ['--season' => '2026', '--limit-games' => $limit, '--rebuild' => true]))->toBe(0);
    expect(TeamPossessionMetric::query()->where('games_sampled', 1)->count())->toBe($expected);
    expect(TeamPossessionMetric::query()->where('games_sampled', 99)->count())->toBe(0);
})->with(['zero means all' => ['0', 4], 'positive one' => ['1', 2]]);
