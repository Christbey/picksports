<?php

use App\Actions\OddsApi\NFL\SyncPlayerPropsForGames;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Models\OddsApiPlayerMapping;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\NFL\NflPlayerPropCoverage;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;

beforeEach(function () {
    $this->travelTo('2026-09-20 10:00:00');
    $this->team = Team::factory()->create();
    $this->opponent = Team::factory()->create();
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'full_name' => 'Audit Runningback', 'position' => 'RB']);
    $this->game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id, 'season' => 2026, 'season_type' => 2, 'game_date' => '2026-09-20', 'game_time' => '20:05:00', 'status' => 'STATUS_SCHEDULED']);
    $this->prop = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $this->player->id, 'player_name' => $this->player->full_name, 'market' => 'player_rush_attempts', 'line' => 12.5, 'over_price' => -104, 'under_price' => -123, 'bookmaker' => 'testbook', 'fetched_at' => now()]);
    $this->analyzer = app(PlayerPropAnalyzer::class);
    $this->invoke = fn ($method, ...$args) => (new ReflectionMethod(PlayerPropAnalyzer::class, $method))->invoke($this->analyzer, ...$args);
    $this->history = function (int $season, string $date, ?int $attempts, array $gameExtra = []) {
        $game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id, 'season' => $season, 'season_type' => 2, 'game_date' => $date, 'status' => 'STATUS_FINAL', ...$gameExtra]);
        PlayerStat::factory()->create(['player_id' => $this->player->id, 'team_id' => $this->team->id, 'game_id' => $game->id, 'rushing_attempts' => $attempts]);

        return $game;
    };
    $this->analyze = fn () => $this->analyzer->analyzeProps('NFL', gameFilter: $this->game->id, attachNarratives: false);
});

test('current workload is not diluted by previous season backup games or counted as a large current sample', function () {
    for ($i = 1; $i <= 15; $i++) {
        ($this->history)(2025, sprintf('2025-10-%02d', $i), 5);
    }
    ($this->history)(2026, '2026-09-13', 15);
    ($this->analyze)();
    $prop = $this->prop->fresh();
    expect(data_get($prop->confidence_decomposition, 'history.current_season_games'))->toBe(1)
        ->and(data_get($prop->confidence_decomposition, 'history.eligible_history_games'))->toBe(16)
        ->and(data_get($prop->confidence_decomposition, 'stat_summary.season_avg'))->toEqual(15)
        ->and(data_get($prop->confidence_decomposition, 'stat_summary.last5_avg'))->toEqual(15)
        ->and($prop->recommended_side)->not->toBe('Under')
        ->and($prop->confidence_score)->toBeLessThanOrEqual(72);
});

test('missing stats never become zero outcomes or under wins while reported zero is retained', function () {
    $stats = collect([new PlayerStat(['rushing_attempts' => 20]), new PlayerStat(['rushing_attempts' => null]), new PlayerStat(['rushing_attempts' => 0])]);
    expect(($this->invoke)('averageStatValue', $stats, 'rushing_attempts'))->toEqual(10);
    $record = ($this->invoke)('coverRecordFromStats', $stats, 'rushing_attempts', 12.5, 'Under');
    expect($record['games'])->toBe(2)->and($record['wins'])->toBe(1);
    expect(($this->invoke)('statValue', new PlayerStat(['rushing_touchdowns' => 1, 'receiving_touchdowns' => null]), 'total_touchdowns'))->toBeNull();
});

test('model history excludes missing preseason future old season and transferred-team samples', function () {
    ($this->history)(2025, '2025-10-01', 10);
    ($this->history)(2025, '2025-10-08', 10);
    ($this->history)(2026, '2026-09-13', 20);
    ($this->history)(2026, '2026-09-12', null);
    ($this->history)(2026, '2026-08-20', 100, ['season_type' => 1]);
    ($this->history)(2026, '2026-09-27', 100);
    ($this->history)(2024, '2024-10-01', 100);
    $transferred = ($this->history)(2025, '2025-10-15', 100);
    PlayerStat::where('game_id', $transferred->id)->update(['team_id' => $this->opponent->id]);
    ($this->analyze)();
    $meta = $this->prop->fresh()->confidence_decomposition;
    expect(data_get($meta, 'history.eligible_history_games'))->toBe(3)
        ->and(data_get($meta, 'stat_summary.season_avg'))->toEqual(20);
});

test('defensive evidence uses the other offense and exactly the latest twelve regular season games', function () {
    for ($i = 1; $i <= 13; $i++) {
        $g = ($this->history)(2025, sprintf('2025-10-%02d', $i), 10);
        TeamStat::factory()->create(['game_id' => $g->id, 'team_id' => $this->team->id, 'rushing_attempts' => $i === 1 ? 100 : 20]);
        TeamStat::factory()->create(['game_id' => $g->id, 'team_id' => $this->opponent->id, 'rushing_attempts' => 5]);
    }
    $config = ($this->invoke)('getSportConfig', 'NFL');
    $context = ($this->invoke)('buildContextAdjustments', $this->game, $this->player->id, $this->team->id, $this->opponent->id, 'player_rush_attempts', $config);
    expect($context['opponent_evidence']['games'])->toBe(12)
        ->and($context['opponent_evidence']['allowed_average'])->toEqual(20);
    TeamStat::where('team_id', $this->opponent->id)->update(['rushing_attempts' => 80]);
    $changed = ($this->invoke)('buildContextAdjustments', $this->game, $this->player->id, $this->team->id, $this->opponent->id, 'player_rush_attempts', $config);
    expect($changed['opponent_evidence']['allowed_average'])->toEqual(20);
});

test('a favorable no-vig comparison cannot approve a negative expected value price', function () {
    $this->prop->fill(['over_price' => -300, 'under_price' => 100]);
    $result = ($this->invoke)('calculateEdge', $this->prop, 15.2, 15.2, 15.2, null, null, null, false, ['std_dev' => 5], ['combined_factor' => 1], 100, 100, 0, 0, 17);
    expect($result['recommendation'])->toBeNull();
    expect(($this->invoke)('positivePriceEdge', .8, -300))->toBeTrue()
        ->and(($this->invoke)('positivePriceEdge', .7, -300))->toBeFalse()
        ->and(($this->invoke)('positivePriceEdge', .9, 0))->toBeFalse();
});

test('integer count lines allocate push mass and half lines do not', function () {
    $integer = ($this->invoke)('nflOutcomeProbabilities', 12, 12, 5, 'player_rush_attempts');
    expect($integer['push'])->toBeGreaterThan(0)->and(array_sum($integer))->toEqualWithDelta(1, .000001);
    $half = ($this->invoke)('nflOutcomeProbabilities', 12, 12.5, 5, 'player_rush_attempts');
    expect($half['push'])->toEqualWithDelta(0, .000001)->and(array_sum($half))->toEqualWithDelta(1, .000001);
});

test('uncertain player availability is a named hold rather than a neutral multiplier', function () {
    for ($i = 1; $i <= 5; $i++) {
        ($this->history)(2026, sprintf('2026-09-%02d', $i), 20);
    }
    PlayerInjury::create(['player_id' => $this->player->id, 'team_id' => $this->team->id, 'injury_key' => 'audit', 'status' => 'Questionable', 'is_active' => true, 'injury_date' => now()]);
    ($this->analyze)();
    expect($this->prop->fresh()->recommended_side)->toBeNull()
        ->and(data_get($this->prop->fresh()->confidence_decomposition, 'analysis_disposition.reason'))->toBe('player_availability_uncertain');
});

test('live completed and graded NFL forecasts cannot be overwritten by reanalysis', function (string $status, bool $graded) {
    $this->game->update(['status' => $status]);
    $this->prop->update(['recommended_side' => 'Under', 'confidence_score' => 88, 'graded_at' => $graded ? now() : null]);
    $before = $this->prop->fresh()->getAttributes();
    ($this->analyze)();
    expect($this->prop->fresh()->getAttributes())->toBe($before);
})->with([['STATUS_IN_PROGRESS', false], ['STATUS_FINAL', true], ['STATUS_SCHEDULED', true]]);

test('a passed kickoff freezes snapshots even if the status feed still says scheduled', function () {
    $this->game->update(['game_time' => '00:05:00']);
    $before = $this->prop->fresh()->getAttributes();
    ($this->analyze)();
    expect($this->prop->fresh()->getAttributes())->toBe($before);
});

test('old model evaluations are unprocessed even when the quote is unchanged', function () {
    $this->prop->update(['confidence_decomposition' => ['analysis_disposition' => ['status' => 'hold', 'reason' => 'no_edge', 'model_version' => 'old', 'evaluated_at' => now()->toIso8601String(), 'quote_fingerprint' => app(NflPlayerPropCoverage::class)->quoteFingerprint($this->prop)]]]);
    expect(app(NflPlayerPropCoverage::class)->forGame($this->game->id)['unprocessed_quotes'])->toBe(1);
});

test('shadow preview uses the daily quote window without writing snapshots or suggested mappings', function () {
    for ($i = 1; $i <= 4; $i++) {
        ($this->history)(2026, sprintf('2026-09-%02d', $i), 20);
    }
    $this->prop->update(['fetched_at' => now()->subHours(18)]);
    $before = $this->prop->fresh()->getAttributes();
    $rows = $this->analyzer->previewNflGame($this->game);
    expect($rows)->toHaveCount(1)->and($this->prop->fresh()->getAttributes())->toBe($before);
    $this->prop->update(['player_name' => 'Completely Unmatched Audit Name']);
    $count = OddsApiPlayerMapping::count();
    $this->analyzer->previewNflGame($this->game);
    expect(OddsApiPlayerMapping::count())->toBe($count);
    $this->prop->update(['fetched_at' => now()->subHours(25)]);
    expect($this->analyzer->previewNflGame($this->game))->toBe([]);
});

test('a missing depth chart cannot retain a strong confidence label', function () {
    $result = ($this->invoke)('calculateEdge', $this->prop, 5, 5, 5, null, null, null, false, ['std_dev' => 1], ['combined_factor' => 1, 'availability' => ['reason' => 'missing_or_unlinked_depth']], 100, 100, 0, 0, 17);
    expect($result['recommendation'])->toBe('Under')->and($result['confidence'])->toBeLessThanOrEqual(68);
});

test('calibration can isolate the new model from old predictions and exclude pushes', function () {
    $this->prop->update(['graded_at' => now(), 'hit_over' => true, 'predicted_over_probability' => 80, 'confidence_decomposition' => ['schema_version' => PlayerPropAnalyzer::NFL_MODEL_VERSION]]);
    $old = $this->prop->replicate();
    $old->bookmaker = 'oldbook';
    $old->predicted_over_probability = 1;
    $old->confidence_decomposition = ['schema_version' => 'player-prop-signal-v2'];
    $old->save();
    $push = $this->prop->replicate();
    $push->bookmaker = 'pushbook';
    $push->hit_over = null;
    $push->save();
    $this->artisan('sports:report-player-props-calibration', ['sport' => 'nfl', '--season' => 2026, '--model-version' => PlayerPropAnalyzer::NFL_MODEL_VERSION, '--min-sample' => 1])
        ->expectsTable(['Sample', 'Brier', 'LogLoss', 'ECE(10-bin)', 'MAE Prob'], [['1', '0.0400', '0.2231', '0.2000', '0.2000']])
        ->assertSuccessful();
});

test('quote sync preserves existing NFL snapshots at or after kickoff', function (bool $crossDuringFetch) {
    $this->game->update(['odds_api_event_id' => 'audit-event', 'status' => $crossDuringFetch ? 'STATUS_SCHEDULED' : 'STATUS_IN_PROGRESS']);
    $before = $this->prop->fresh()->getAttributes();
    $service = Mockery::mock(OddsApiService::class);
    $service->shouldReceive('getOdds')->once()->andReturn([['id' => 'audit-event', 'home_team' => 'Home', 'away_team' => 'Away', 'commence_time' => '2026-09-20T20:05:00Z']]);
    if ($crossDuringFetch) {
        $service->shouldReceive('getPlayerProps')->once()->andReturnUsing(function () {
            $this->travelTo('2026-09-21 00:00:00');

            return ['bookmakers' => []];
        });
    } else {
        $service->shouldNotReceive('getPlayerProps');
    }
    $action = new SyncPlayerPropsForGames($service, app(SportsViewCache::class));
    expect($action->execute(null, 'americanfootball_nfl'))->toBe(0)
        ->and($this->prop->fresh()->getAttributes())->toBe($before);
})->with([false, true]);
