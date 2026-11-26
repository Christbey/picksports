<?php

use App\Models\MarketQuote;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use App\Services\NFL\NflCandidateDecisionPolicy;
use App\Services\NFL\Research\ResearchRefreshPolicy;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo('2026-11-25 12:00:00 America/Chicago');
});

it('rejects future or mismatched research without treating a matching fingerprint as authority', function (string $variant, string $status) {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => '2026-11-26', 'game_time' => '01:00:00']);
    $report = new SportsGameContextReport([
        'sport' => 'nfl', 'game_id' => $game->id, 'status' => $status,
        'researched_at' => now()->subMinute(), 'expires_at' => now()->addHour(),
        'raw_payload' => ['research_fingerprint' => 'same', 'candidate_hash' => 'same',
            'analysis_quality' => ['version' => 1, 'rejected_claims' => 0, 'unverified_questions' => 0],
            'decision_research' => ['supporting' => [['claim' => 'support']], 'opposing' => [['claim' => 'opposition']],
                'unresolved' => [['blocking' => true, 'evidence_status' => 'reference_verified']]]],
    ]);
    $policy = app(ResearchRefreshPolicy::class);
    expect($policy->reusable($report, $game, 'same', 'same'))->toBeTrue();
    match ($variant) {
        'future' => $report->researched_at = now()->addMinute(),
        'wrong_game' => $report->game_id = $game->id + 100,
        'wrong_sport' => $report->sport = 'nba',
    };
    expect($policy->current($report, $game, 'same', 'same'))->toBeFalse()
        ->and($policy->reusable($report, $game, 'same', 'same'))->toBeFalse();
})->with(['future', 'wrong_game', 'wrong_sport'])->with(['ready', 'partial']);

it('requires the opposite quote to have the same game identity and trustworthy pregame ingestion', function (string $variant) {
    $start = now()->toImmutable()->addHours(6);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s')]);
    $odds = ['home_team' => 'Home', 'away_team' => 'Away', 'bookmakers' => [[
        'key' => 'test', 'title' => 'Test', 'last_update' => now()->subMinute()->toIso8601String(),
        'markets' => [['key' => 'spreads', 'outcomes' => [
            ['name' => 'Home', 'point' => -3, 'price' => -110],
            ['name' => 'Away', 'point' => 3, 'price' => -110],
        ]]],
    ]]];
    app(GameOddsSnapshotRecorder::class)->record('nfl', $game, ['id' => 'boundary', 'commence_time' => $start->toIso8601String()], $odds, Carbon::parse(now()->subMinute()));
    $policy = app(NflCandidateDecisionPolicy::class);
    $selection = ['market_type' => 'spread', 'market_key' => 'spreads', 'side' => 'home', 'line' => -3, 'edge' => 3, 'model_probability' => null];
    expect($policy->entryQuote($game, $selection, now()->toImmutable(), $start))->not->toBeNull();
    $opposite = MarketQuote::where('game_id', $game->id)->where('side', 'away')->firstOrFail();
    $changes = match ($variant) {
        'wrong_game' => ['game_id' => $game->id + 100],
        'wrong_table' => ['game_table' => 'nba_games'],
        'wrong_sport' => ['sport' => 'nba'],
        'not_pregame' => ['is_pregame' => false],
        'future_capture' => ['captured_at' => now()->addMinute()],
        'stale_capture' => ['captured_at' => now()->subDays(2)],
        'invalid_observation', 'invalid_selected_observation' => ['metadata' => ['provider_observed_at' => 'invalid']],
    };
    $changedQuote = $variant === 'invalid_selected_observation'
        ? MarketQuote::where('game_id', $game->id)->where('side', 'home')->firstOrFail() : $opposite;
    $changedQuote->forceFill($changes)->save();
    expect($policy->entryQuote($game, $selection, now()->toImmutable(), $start))->toBeNull();
})->with(['wrong_game', 'wrong_table', 'wrong_sport', 'not_pregame', 'future_capture', 'stale_capture', 'invalid_observation', 'invalid_selected_observation']);
