<?php

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\BettingRecommendations\NflPropAvailabilityContext;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-09 12:00:00', 'America/Chicago'));
    $this->team = Team::factory()->create();
    $this->opponent = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-09-10', 'game_time' => '00:20:00']);
    $this->starter = Player::factory()->create(['team_id' => $this->team->id, 'position' => 'RB', 'full_name' => 'Rhamondre Stevenson']);
    $this->backup = Player::factory()->create(['team_id' => $this->team->id, 'position' => 'RB', 'full_name' => 'TreVeyon Henderson']);
    foreach ([$this->starter, $this->backup] as $rank => $player) {
        DepthChartEntry::create(['team_id' => $this->team->id, 'season' => 2026, 'player_id' => $player->id,
            'espn_athlete_id' => $player->espn_id, 'position_slot_key' => 'rb', 'position_code' => 'RB', 'depth_rank' => $rank + 1]);
    }
    for ($i = 0; $i < 8; $i++) {
        $game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
            'season' => 2025, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => now()->subDays(20 + $i * 7)->toDateString()]);
        foreach ([$this->starter, $this->backup] as $player) {
            PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $player->id, 'team_id' => $this->team->id,
                'rushing_attempts' => $player->is($this->starter) ? 12 : 10, 'rushing_yards' => 50, 'receiving_targets' => 4, 'receptions' => 3]);
        }
    }
    $this->injury = PlayerInjury::create(['player_id' => $this->backup->id, 'team_id' => $this->team->id,
        'injury_key' => 'henderson-ankle', 'status' => 'Out', 'is_active' => true, 'injury_date' => '2026-09-08']);
    $this->resolve = fn ($market = 'player_rush_attempts') => app(NflPropAvailabilityContext::class)->resolve($this->game, $this->starter->id, $market);
});

test('raises starter workload for a newly ruled out teammate and records evidence', function () {
    $context = ($this->resolve)();
    expect($context['factor'])->toBe(1.15)->and($context['reason'])->toBe('confirmed_teammate_absence')
        ->and($context['absences'][0]['player_id'])->toBe($this->backup->id);
    expect(($this->resolve)('player_receptions')['factor'])->toBe(1.15);
});

test('does not reallocate questionable doubtful or inactive injuries', function ($status, $active) {
    $this->injury->update(['status' => $status, 'is_active' => $active]);
    expect(($this->resolve)()['factor'])->toBe(1.0);
})->with([['Questionable', true], ['Doubtful', true], ['Out', false]]);

test('does not use stale injury or depth observations', function ($table, $age) {
    DB::table($table)->update(['updated_at' => now()->subHours($age)]);
    expect(($this->resolve)()['factor'])->toBe(1.0);
})->with([['nfl_player_injuries', 13], ['nfl_depth_chart_entries', 193]]);

test('does not infer workload from an unlinked position group', function () {
    DepthChartEntry::where('player_id', $this->backup->id)->update(['player_id' => null, 'espn_athlete_id' => 'unknown']);
    expect(($this->resolve)()['reason'])->toBe('missing_or_unlinked_depth')->and(($this->resolve)()['factor'])->toBe(1.0);
});

test('resolves missing depth links by stable ESPN ID without mutating roster assignments', function () {
    DepthChartEntry::where('player_id', $this->backup->id)->update(['player_id' => null]);
    $this->backup->update(['team_id' => $this->opponent->id]);
    expect(($this->resolve)()['factor'])->toBe(1.15)
        ->and($this->backup->fresh()->team_id)->toBe($this->opponent->id);
    expect(DepthChartEntry::where('espn_athlete_id', $this->backup->espn_id)->value('player_id'))->toBeNull();
});

test('does not reuse current injuries for historical games or unsupported markets', function () {
    expect(($this->resolve)('player_pass_yds')['factor'])->toBe(1.0);
    $this->game->update(['status' => 'STATUS_FINAL']);
    expect(($this->resolve)()['reason'])->toBe('not_pregame');
});

test('does not double count an absence already reflected in recent games', function () {
    $this->injury->update(['injury_date' => '2026-09-01']);
    $historical = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-09-05']);
    PlayerStat::factory()->create(['game_id' => $historical->id, 'player_id' => $this->starter->id,
        'team_id' => $this->team->id, 'rushing_attempts' => 20]);
    expect(($this->resolve)()['factor'])->toBe(1.0);
});

test('ignores opposing injuries and preseason workload', function () {
    $this->injury->update(['team_id' => $this->opponent->id]);
    expect(($this->resolve)()['factor'])->toBe(1.0);
    $this->injury->update(['team_id' => $this->team->id]);
    Game::where('status', 'STATUS_FINAL')->update(['season_type' => 1]);
    expect(($this->resolve)()['factor'])->toBe(1.0);
});

test('applies workload to probabilities and invalidates stored props when injury context changes', function () {
    $prop = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $this->starter->id,
        'player_name' => $this->starter->full_name, 'market' => 'player_rush_attempts', 'line' => 16.5,
        'over_price' => -110, 'under_price' => -110]);
    $analyzer = app(PlayerPropAnalyzer::class);
    $this->injury->update(['is_active' => false]);
    $analyzer->analyzeProps('NFL', 3, gameFilter: $this->game->id, attachNarratives: false);
    $baseline = (float) $prop->fresh()->predicted_over_probability;
    $this->injury->update(['is_active' => true]);
    expect($analyzer->precomputedRecommendations('NFL', gameFilter: $this->game->id))->toBeEmpty();
    $analyzer->analyzeProps('NFL', 3, gameFilter: $this->game->id, attachNarratives: false);
    $prop->refresh();
    expect((float) $prop->predicted_over_probability)->toBeGreaterThan($baseline)
        ->and(data_get($prop->confidence_decomposition, 'availability.factor'))->toBe(1.15);
    expect($analyzer->precomputedRecommendations('NFL', gameFilter: $this->game->id))->toHaveCount(1);
    PlayerInjury::create(['player_id' => $this->starter->id, 'team_id' => $this->team->id,
        'injury_key' => 'starter-out', 'status' => 'Out', 'is_active' => true, 'injury_date' => '2026-09-09']);
    expect($analyzer->precomputedRecommendations('NFL', gameFilter: $this->game->id))->toBeEmpty();
    $analyzer->analyzeProps('NFL', 3, gameFilter: $this->game->id, attachNarratives: false);
    expect($prop->fresh()->recommended_side)->toBeNull();
});
