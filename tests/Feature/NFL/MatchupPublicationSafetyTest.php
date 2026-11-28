<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\MatchupSamplePublication;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function matchupPublicationFixture(): array
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    DB::table('nflverse_pbp_plays')->insert(['nflverse_play_key' => 'publication-fixture', 'nflverse_game_id' => '2026_01_A_B',
        'nfl_game_id' => $game->id, 'season' => 2026, 'week' => 1, 'possession_team' => 'A', 'possession_team_id' => $home->id,
        'defense_team' => 'B', 'defense_team_id' => $away->id]);
    $row = ['game_id' => $game->id, 'team_id' => $home->id, 'season' => 2026, 'metric' => 'release_time',
        'player_id' => 'gsis:00-0000001', 'value' => 2.5, 'sample_size' => 20, 'observed_at' => now()->toDateTimeString()];
    DB::table('nfl_matchup_tracking_samples')->insert($row);

    return [$game, $row, app(MatchupSamplePublication::class)];
}

it('refuses changed source joins or game participants before replacing a season', function (string $change) {
    [$game, $row, $publication] = matchupPublicationFixture();
    $connection = DB::connection();
    $snapshot = $publication->snapshot($connection, 2026, false);
    $before = $connection->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson();
    if ($change === 'source') {
        $connection->table('nflverse_pbp_plays')->update(['possession_team' => 'changed']);
    } else {
        $connection->table('nfl_games')->where('id', $game->id)->update(['away_team_id' => Team::factory()->create()->id]);
    }
    expect(fn () => $publication->publish($connection, 'nfl_matchup_tracking_samples', 2026, [$row], $snapshot))->toThrow(RuntimeException::class);
    expect($connection->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson())->toBe($before);
})->with(['source', 'game']);

it('rolls back a deleted season when a subsequent sample insert fails', function () {
    [, $row, $publication] = matchupPublicationFixture();
    $connection = DB::connection();
    $snapshot = $publication->snapshot($connection, 2026, false);
    $before = $connection->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson();
    $quoted = $connection->getQueryGrammar()->wrapTable('nfl_matchup_tracking_samples');
    DB::listen(function (QueryExecuted $query) use ($quoted): void {
        if (str_starts_with(strtolower($query->sql), 'insert into '.strtolower($quoted))) {
            throw new RuntimeException('Synthetic post-insert failure');
        }
    });
    expect(fn () => $publication->publish($connection, 'nfl_matchup_tracking_samples', 2026, [$row], $snapshot))
        ->toThrow(RuntimeException::class, 'Synthetic post-insert failure');
    expect($connection->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson())->toBe($before);
});

it('fails closed at explicit source and projection budgets', function () {
    $publication = app(MatchupSamplePublication::class);
    expect(fn () => $publication->assertRowBudget(MatchupSamplePublication::SOURCE_ROWS + 1))->toThrow(RuntimeException::class);
    expect(fn () => $publication->assertSampleBudget(MatchupSamplePublication::SAMPLES + 1))->toThrow(RuntimeException::class);
    Http::preventStrayRequests();
    Http::fake(function ($request, array $options) {
        expect($options['decode_content'])->toBeFalse();

        return Http::response('x', 200, ['Content-Length' => MatchupSamplePublication::RAW_BYTES + 1]);
    });
    expect(fn () => $publication->download('https://example.invalid/source'))->toThrow(RuntimeException::class);
});

it('refuses changed canonical provider or schedule identity before sample publication', function (string $field, $value) {
    [$game, $row, $publication] = matchupPublicationFixture();
    $connection = DB::connection();
    $snapshot = $publication->snapshot($connection, 2026, false);
    $before = $connection->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson();
    $connection->table('nfl_games')->where('id', $game->id)->update([$field => $value]);
    expect(fn () => $publication->publish($connection, 'nfl_matchup_tracking_samples', 2026, [$row], $snapshot))
        ->toThrow(RuntimeException::class, 'Tracking identity context changed');
    expect($connection->table('nfl_matchup_tracking_samples')->orderBy('id')->get()->toJson())->toBe($before);
})->with([
    ['espn_event_id', 'changed-provider-id'], ['nflverse_game_id', 'changed-nflverse-id'], ['week', 99],
    ['game_date', '2026-11-01'], ['game_time', '23:59:00'], ['season_type', 'corrected-season-type'],
]);
