<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbHistoricalSignalEvidenceBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function observedCfbHistoryGame(Team $home, Team $away, string $day, int $homeScore, int $awayScore): Game
{
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => $day.' 12:00:00']);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id,
        'away_team_id' => $away->id, 'season' => 2026, 'game_date' => $day, 'game_time' => '12:00:00',
        'status' => 'STATUS_FINAL', 'home_score' => $homeScore, 'away_score' => $awayScore, 'neutral_site' => false,
        'conference_game' => true]);
    DB::table('cfb_games')->where('id', $game->id)->update(['created_at' => $day, 'updated_at' => $day.' 18:00:00']);

    return $game;
}

it('batches observed historical evidence, keeps missing stats missing and uses attempt-weighted ratios', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $target = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026,
        'game_date' => '2026-09-21', 'status' => 'STATUS_SCHEDULED']);
    $first = observedCfbHistoryGame($home, $away, '2026-09-01', 30, 20);
    $second = observedCfbHistoryGame($away, $home, '2026-09-08', 14, 21);
    foreach ([[$first, 1, 2], [$second, 1, 10]] as [$game, $made, $attempts]) {
        $stat = TeamStat::create(['game_id' => $game->id, 'team_id' => $home->id, 'team_type' => 'home',
            'third_down_conversions' => $made, 'third_down_attempts' => $attempts, 'passing_yards' => 0]);
        DB::table('cfb_team_stats')->where('id', $stat->id)->update(['created_at' => '2026-09-09', 'updated_at' => '2026-09-09']);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(CfbHistoricalSignalEvidenceBuilder::class)->build($target, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $metrics = $result['home']['windows']['current_season']['metrics'];
    expect($queries)->toBeLessThanOrEqual(4)->and($metrics['points_per_game']['value'])->toBe(25.5)
        ->and($metrics['points_allowed_per_game']['value'])->toBe(17.0)
        ->and($metrics['third_down_rate']['value'])->toBe(0.166667)
        ->and($metrics['third_down_rate']['denominator'])->toBe(12.0)
        ->and($metrics['passing_yards_per_game']['value'])->toBe(0.0)
        ->and($metrics['rushing_yards_per_game']['value'])->toBeNull()
        ->and($metrics['rushing_yards_per_game']['sample_games'])->toBe(0)
        ->and($result['home']['windows']['home']['games'])->toBe(1)
        ->and($result['home']['windows']['away']['games'])->toBe(1)
        ->and($result['home']['rest_days']['value'])->toBe(12);
    DB::table('cfb_team_stats')->where('game_id', $first->id)->update(['updated_at' => '2026-09-22']);
    $partial = app(CfbHistoricalSignalEvidenceBuilder::class)->build($target, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));
    expect($partial['home']['windows']['last5']['metrics']['third_down_rate']['value'])->toBe(0.1)
        ->and($partial['home']['windows']['last5']['metrics']['third_down_rate']['sample_games'])->toBe(1)
        ->and($partial['home']['windows']['last5']['metrics']['third_down_rate']['status'])->toBe('partial');
});

it('excludes future observations and same-day results and reports empty windows explicitly', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $target = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026]);
    $late = observedCfbHistoryGame($home, $away, '2026-09-01', 40, 10);
    DB::table('cfb_games')->where('id', $late->id)->update(['updated_at' => '2026-09-22']);
    observedCfbHistoryGame($home, $away, '2026-09-20', 50, 10);
    $result = app(CfbHistoricalSignalEvidenceBuilder::class)->build($target, CarbonImmutable::parse('2026-09-20 20:00:00'), CarbonImmutable::parse('2026-09-21'));
    expect($result['home']['windows']['last5']['games'])->toBe(0)
        ->and($result['home']['windows']['last5']['metrics']['win_rate']['status'])->toBe('missing')
        ->and($result['home']['windows']['last5']['metrics']['win_rate']['value'])->toBeNull();
});

it('grades ATS and totals from the last stored pregame lines without counting pushes as losses', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $target = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026]);
    $game = observedCfbHistoryGame($home, $away, '2026-09-01', 30, 20);
    $snapshot = GameOddsSnapshot::create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
        'source' => 'test', 'captured_at' => '2026-09-01 10:00:00', 'payload_hash' => hash('sha256', 'historical-test'), 'odds_data' => []]);
    foreach ([['spreads', 'home', -8, '10:00:00', '10:00:00'], ['spreads', 'home', -10, '09:00:00', '11:00:00'],
        ['spreads', 'home', -40, '11:00:00', '13:00:00'], ['totals', 'over', 45, '11:00:00', '11:00:00']] as $i => [$market, $side, $line, $observed, $stored]) {
        $quote = MarketQuote::create(['game_odds_snapshot_id' => $snapshot->id, 'sport' => 'cfb', 'game_table' => 'cfb_games',
            'game_id' => $game->id, 'source' => 'test', 'bookmaker_key' => 'test', 'market_key' => $market, 'side' => $side,
            'line' => $line, 'captured_at' => '2026-09-01 '.$observed, 'is_pregame' => true, 'quote_hash' => hash('sha256', 'history'.$i)]);
        DB::table('market_quotes')->where('id', $quote->id)->update(['created_at' => '2026-09-01 '.$stored]);
    }
    $result = app(CfbHistoricalSignalEvidenceBuilder::class)->build($target, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));
    $metrics = $result['home']['windows']['last5']['metrics'];
    expect($metrics['ats_push_rate']['value'])->toBe(1.0)->and($metrics['ats_cover_rate']['value'])->toBeNull()
        ->and($metrics['over_rate']['value'])->toBe(1.0)->and($metrics['under_rate']['value'])->toBe(0.0);
});
