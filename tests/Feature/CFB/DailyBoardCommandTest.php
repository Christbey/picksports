<?php

use App\Actions\OddsApi\CFB\SyncPlayerPropsForGames;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\Team;
use App\Models\PredictionMarket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-26 14:00:00', 'UTC'));
    config(['cfb.data.source_disk' => 'local']);
    Storage::fake('local');
});

test('combined board includes next UTC day games and both forecasts and eligible props', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => '2026-09-27', 'game_time' => '02:30:00', 'status' => 'STATUS_SCHEDULED', 'season' => 2026]);
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'detail_sport' => 'cfb', 'phase' => 'pregame', 'publication_state' => 'published']);
    $game->update(['sport_event_id' => $prediction->sport_event_id]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -7]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 51]);
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'espn_id' => '987654', 'full_name' => 'Example Quarterback']);
    $attributes = ['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => $player->full_name,
        'market' => 'player_pass_yds', 'line' => 200.5, 'over_price' => -110, 'under_price' => -110, 'fetched_at' => now(),
        'recommended_side' => 'Over', 'confidence_score' => 70,
        'confidence_decomposition' => ['schema_version' => 'player-prop-signal-v2', 'stat_summary' => ['season_avg' => 250], 'cover_record' => ['season' => ['games' => 6]]]];
    $fresh = PlayerProp::create($attributes);
    PlayerProp::create([...$attributes, 'fetched_at' => now()->subHours(2), 'confidence_score' => 95]);
    $this->artisan('cfb:daily-board', ['--date' => '2026-09-26'])->assertSuccessful();
    $report = json_decode(Storage::get('cfb/reports/2026-09-26/daily-board.json'), true);
    expect($report['coverage']['games'])->toBe(1)
        ->and($report['coverage']['current_prop_quotes'])->toBe(2)
        ->and($report['coverage']['fresh_prop_quotes'])->toBe(1)
        ->and($report['games'][0]['home_points'])->toBe(29)
        ->and($report['games'][0]['away_points'])->toBe(22)
        ->and($report['player_props'])->toHaveCount(1)
        ->and($report['player_props'][0]['quote_id'])->toBe($fresh->id)
        ->and($report['player_props'][0]['score_is_probability'])->toBeFalse();
});

test('combined board explicitly reports missing quotes and forecasts', function () {
    Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => '2026-09-26', 'game_time' => '20:00:00']);
    $this->artisan('cfb:daily-board', ['--date' => '2026-09-26'])->assertSuccessful();
    $report = json_decode(Storage::get('cfb/reports/2026-09-26/daily-board.json'), true);
    expect($report['player_props'])->toBe([])->and($report['coverage']['forecasts'])->toBe(0)
        ->and($report['games'][0]['prop_status'])->toBe('no_current_quotes');
});

test('prop preparation failure is surfaced after successful quote import', function () {
    $sync = Mockery::mock(SyncPlayerPropsForGames::class);
    $sync->shouldReceive('execute')->once()->with('2026-09-26', null, false)
        ->andReturn(['games' => 1, 'stored' => 2, 'empty' => 0, 'failed' => 0, 'recommendations' => 0]);
    app()->instance(SyncPlayerPropsForGames::class, $sync);
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $this->artisan('cfb:sync-player-props', ['--date' => '2026-09-26', '--prepare' => true])->assertFailed();
    Process::assertRan(fn ($process) => in_array('cfb:prepare-player-props', $process->command, true)
        && in_array('--date=2026-09-26', $process->command, true));
});
