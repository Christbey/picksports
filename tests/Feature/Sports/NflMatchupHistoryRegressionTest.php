<?php

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\User;
use App\Services\Sports\GameMatchupContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
    config(['trends.timezones.nfl.display' => 'America/New_York']);
    $this->bills = Team::factory()->create(['abbreviation' => 'BUF']);
    $this->lions = Team::factory()->create(['abbreviation' => 'DET']);
    $this->game = Game::factory()->create([
        'home_team_id' => $this->bills->id, 'away_team_id' => $this->lions->id,
        'season' => 2026, 'season_type' => '2', 'week' => 2,
        'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-09-18', 'game_time' => '00:15:00',
    ]);
});

it('includes the 2024 meeting with correct venue orientation while retaining current season records', function () {
    Sanctum::actingAs(User::factory()->create());
    $meeting = Game::factory()->create([
        'home_team_id' => $this->lions->id, 'away_team_id' => $this->bills->id,
        'season' => 2024, 'season_type' => 'Regular Season',
        'status' => 'STATUS_FINAL', 'game_date' => '2024-12-15', 'game_time' => '21:25:00',
        'home_score' => 42, 'away_score' => 48,
    ]);
    foreach ([['2026-08-20', '1', 'STATUS_FINAL'], ['2026-09-19', '2', 'STATUS_FINAL'], ['2026-09-10', '2', 'STATUS_IN_PROGRESS']] as [$date, $type, $status]) {
        Game::factory()->create([
            'home_team_id' => $this->bills->id, 'away_team_id' => $this->lions->id,
            'season' => 2026, 'season_type' => $type, 'status' => $status,
            'game_date' => $date, 'game_time' => '00:15:00', 'home_score' => 50, 'away_score' => 10,
        ]);
    }
    $other = Team::factory()->create();
    Game::factory()->create([
        'home_team_id' => $this->bills->id, 'away_team_id' => $other->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-13', 'game_time' => '17:00:00', 'home_score' => 21, 'away_score' => 7,
    ]);

    $response = $this->getJson('/api/v2/sports/nfl/games/'.$this->game->id)->assertOk();
    $rows = collect($response->json('data.matchup_context.rows'))->keyBy('key');
    expect($rows['head_to_head']['home']['display'])->toBe('1-0')
        ->and($rows['head_to_head']['away']['display'])->toBe('0-1')
        ->and($rows['head_to_head']['scope'])->toBe('available_prior_seasons')
        ->and($rows['head_to_head']['latest_meeting'])->toBe([
            'game_id' => $meeting->id, 'season' => 2024, 'game_date' => '2024-12-15',
            'away_team_id' => $this->bills->id, 'home_team_id' => $this->lions->id,
            'away_abbreviation' => 'BUF', 'home_abbreviation' => 'DET', 'away_score' => 48, 'home_score' => 42,
        ])
        ->and($rows['overall']['home']['display'])->toBe('1-0')
        ->and($rows['overall']['away']['games'])->toBe(0);
});

it('classifies UTC overnight games as local night and local afternoons as day', function () {
    foreach ([['2026-09-11', '00:15:00', 24, 17], ['2026-09-13', '17:00:00', 10, 20]] as [$date, $time, $home, $away]) {
        Game::factory()->create([
            'home_team_id' => $this->bills->id, 'away_team_id' => $this->lions->id,
            'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL',
            'game_date' => $date, 'game_time' => $time, 'home_score' => $home, 'away_score' => $away,
        ]);
    }
    $rows = collect(app(GameMatchupContextService::class)->forGame($this->game)['rows'])->keyBy('key');
    expect($rows['time_bucket_record']['label'])->toBe('Night record')
        ->and($rows['time_bucket_record']['home']['display'])->toBe('1-0')
        ->and($rows['head_to_head']['latest_meeting']['game_date'])->toBe('2026-09-13');
});

it('does not include same-day history when the target kickoff time is unknown', function () {
    $this->game->setAttribute('game_time', null);
    Game::factory()->create([
        'home_team_id' => $this->bills->id, 'away_team_id' => $this->lions->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-18', 'game_time' => '21:00:00', 'home_score' => 21, 'away_score' => 10,
    ]);
    $rows = collect(app(GameMatchupContextService::class)->forGame($this->game)['rows'])->keyBy('key');
    expect($rows['head_to_head']['latest_meeting'])->toBeNull()
        ->and($rows['head_to_head']['home']['games'])->toBe(0)
        ->and($rows->has('time_bucket_record'))->toBeFalse();
});

it('selects an available depth chart backup and labels quarterback history as cross-season', function () {
    $out = Player::factory()->create(['team_id' => $this->bills->id, 'position' => 'QB', 'full_name' => 'Unavailable Starter']);
    $backup = Player::factory()->create(['team_id' => $this->bills->id, 'position' => 'QB', 'full_name' => 'Available Backup']);
    foreach ([$out, $backup] as $rank => $player) {
        DepthChartEntry::create([
            'team_id' => $this->bills->id, 'player_id' => $player->id, 'season' => 2026,
            'position_slot_key' => 'QB', 'position_code' => 'QB', 'depth_rank' => $rank + 1,
            'source_updated_at' => now()->subHour(),
        ]);
    }
    PlayerInjury::create([
        'team_id' => $this->bills->id, 'player_id' => $out->id, 'injury_key' => 'qb-out',
        'status' => 'Out', 'is_active' => true, 'source_updated_at' => now()->subHour(),
    ]);
    $oldGame = Game::factory()->create([
        'home_team_id' => $this->lions->id, 'away_team_id' => $this->bills->id,
        'season' => 2024, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2024-12-15', 'game_time' => '21:25:00', 'home_score' => 42, 'away_score' => 48,
    ]);
    PlayerStat::create(['team_id' => $this->bills->id, 'player_id' => $backup->id, 'game_id' => $oldGame->id, 'passing_attempts' => 30]);
    DB::enableQueryLog();
    $rows = collect(app(GameMatchupContextService::class)->forGame($this->game)['rows'])->keyBy('key');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($rows['starter_matchup']['subtitle'])->toContain('Available prior seasons', 'Available Backup')
        ->not->toContain('Unavailable Starter')
        ->and($rows['starter_matchup']['away']['display'])->toBe('0-1')
        ->and($queries->contains(fn ($sql) => str_contains($sql, 'exists') && str_contains($sql, 'nfl_player_stats')))->toBeTrue()
        ->and($queries->contains(fn ($sql) => str_contains(strtolower($sql), 'select distinct')))->toBeFalse();
});

it('does not name a previous-game quarterback who is now confirmed out', function () {
    $out = Player::factory()->create(['team_id' => $this->bills->id, 'position' => 'QB']);
    $prior = Game::factory()->create([
        'home_team_id' => $this->bills->id, 'away_team_id' => $this->lions->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-13', 'game_time' => '17:00:00', 'home_score' => 21, 'away_score' => 7,
    ]);
    PlayerStat::create(['team_id' => $this->bills->id, 'player_id' => $out->id, 'game_id' => $prior->id, 'passing_attempts' => 30]);
    PlayerInjury::create([
        'team_id' => $this->bills->id, 'player_id' => $out->id, 'injury_key' => 'qb-out',
        'status' => 'Out', 'is_active' => true, 'source_updated_at' => now()->subHour(),
    ]);
    $rows = collect(app(GameMatchupContextService::class)->forGame($this->game)['rows'])->keyBy('key');
    expect($rows->has('starter_matchup'))->toBeFalse();
});

it('does not turn an ambiguous depth chart into a claimed likely quarterback', function () {
    foreach (range(1, 2) as $index) {
        $player = Player::factory()->create(['team_id' => $this->bills->id, 'position' => 'QB']);
        DepthChartEntry::create([
            'team_id' => $this->bills->id, 'player_id' => $player->id, 'season' => 2026,
            'position_slot_key' => 'QB', 'position_code' => 'QB', 'depth_rank' => 1,
            'source_updated_at' => now()->subHour(),
        ]);
    }
    $rows = collect(app(GameMatchupContextService::class)->forGame($this->game)['rows'])->keyBy('key');
    expect($rows->has('starter_matchup'))->toBeFalse();
});

it('uses the pre-kickoff depth snapshot and never later roster observations for a finished game', function () {
    $this->game->update(['status' => 'STATUS_FINAL', 'game_date' => '2026-09-11', 'game_time' => '00:15:00']);
    $old = Player::factory()->create(['team_id' => $this->bills->id, 'position' => 'QB', 'full_name' => 'Pregame Starter']);
    $new = Player::factory()->create(['team_id' => $this->bills->id, 'position' => 'QB', 'full_name' => 'Later Starter']);
    foreach ([[$old, '2026-09-10 12:00:00'], [$new, '2026-09-12 12:00:00']] as [$player, $observedAt]) {
        $snapshot = DepthChartSnapshot::create([
            'team_id' => $this->bills->id, 'season' => 2026, 'espn_team_id' => 'buf',
            'snapshot_uuid' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $observedAt),
            'observed_at' => $observedAt, 'source_updated_at' => $observedAt,
        ]);
        $snapshot->entries()->create([
            'player_id' => $player->id, 'position_slot_key' => 'QB', 'position_code' => 'QB', 'depth_rank' => 1,
            'observed_at' => $observedAt, 'source_updated_at' => $observedAt,
        ]);
    }
    PlayerInjury::create([
        'team_id' => $this->bills->id, 'player_id' => $old->id, 'injury_key' => 'later-injury',
        'status' => 'Out', 'is_active' => true, 'source_updated_at' => '2026-09-12 12:00:00',
    ]);
    $rows = collect(app(GameMatchupContextService::class)->forGame($this->game)['rows'])->keyBy('key');
    expect($rows['starter_matchup']['subtitle'])->toContain('Pregame Starter')->not->toContain('Later Starter');
});
