<?php

use App\Actions\MLB\ReconcileGameScoreFromTeamStats;
use App\Actions\Validation\Checks\FinalizedDataCompletenessCheck;
use App\Models\MLB\Game;
use App\Models\MLB\Play;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;

uses()->group('mlb');

it('fills missing final scores from home and away team stat runs', function () {
    $game = mlbReconciliationGame(homeScore: null, awayScore: null, status: 'STATUS_FINAL');
    mlbTeamRuns($game, homeRuns: 4, awayRuns: 2);

    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game);

    expect($result['status'])->toBe('updated')
        ->and($result['reason'])->toBe('filled_missing_final_score_from_team_stats')
        ->and($game->fresh()->home_score)->toBe(4)
        ->and($game->fresh()->away_score)->toBe(2);
});

it('does not reconcile scheduled or live games', function (string $status) {
    $game = mlbReconciliationGame(homeScore: null, awayScore: null, status: $status);
    mlbTeamRuns($game, homeRuns: 4, awayRuns: 2);

    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game);

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('game_not_final')
        ->and($game->fresh()->home_score)->toBeNull()
        ->and($game->fresh()->away_score)->toBeNull();
})->with(['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_DELAYED', 'STATUS_POSTPONED', 'STATUS_SUSPENDED']);

it('is idempotent when scores already match team stat runs', function () {
    $game = mlbReconciliationGame(homeScore: 4, awayScore: 2, status: 'STATUS_FINAL');
    mlbTeamRuns($game, homeRuns: 4, awayRuns: 2);

    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game);

    expect($result['status'])->toBe('unchanged')
        ->and($result['reason'])->toBe('scores_already_match_team_stats_runs')
        ->and($game->fresh()->home_score)->toBe(4)
        ->and($game->fresh()->away_score)->toBe(2);
});

it('does not overwrite score conflicts by default', function () {
    $game = mlbReconciliationGame(homeScore: 5, awayScore: 2, status: 'STATUS_FINAL');
    mlbTeamRuns($game, homeRuns: 4, awayRuns: 2);

    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game);

    expect($result['status'])->toBe('conflict')
        ->and($result['reason'])->toBe('game_score_conflicts_with_team_stats_runs')
        ->and($game->fresh()->home_score)->toBe(5)
        ->and($game->fresh()->away_score)->toBe(2);
});

it('fills a partial missing score when the present side matches team stats', function () {
    $game = mlbReconciliationGame(homeScore: 4, awayScore: null, status: 'STATUS_FINAL');
    mlbTeamRuns($game, homeRuns: 4, awayRuns: 2);

    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game);

    expect($result['status'])->toBe('updated')
        ->and($game->fresh()->home_score)->toBe(4)
        ->and($game->fresh()->away_score)->toBe(2);
});

it('skips final games when team stat runs are incomplete', function () {
    $game = mlbReconciliationGame(homeScore: null, awayScore: null, status: 'STATUS_FINAL');
    mlbTeamRuns($game, homeRuns: 4, awayRuns: null);

    $result = app(ReconcileGameScoreFromTeamStats::class)->execute($game);

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('missing_team_stats_runs')
        ->and($game->fresh()->home_score)->toBeNull()
        ->and($game->fresh()->away_score)->toBeNull();
});

it('supports dry-run command mode without writing scores', function () {
    $game = mlbReconciliationGame(homeScore: null, awayScore: null, status: 'STATUS_FINAL', season: 2026);
    mlbTeamRuns($game, homeRuns: 6, awayRuns: 1);

    $this->artisan('mlb:reconcile-final-scores', [
        '--season' => 2026,
        '--dry-run' => true,
    ])->assertExitCode(0);

    expect($game->fresh()->home_score)->toBeNull()
        ->and($game->fresh()->away_score)->toBeNull();
});

it('applies score reconciliation from the command', function () {
    $game = mlbReconciliationGame(homeScore: null, awayScore: null, status: 'STATUS_FINAL', season: 2026);
    mlbTeamRuns($game, homeRuns: 6, awayRuns: 1);

    $this->artisan('mlb:reconcile-final-scores', [
        '--season' => 2026,
    ])->assertExitCode(0);

    expect($game->fresh()->home_score)->toBe(6)
        ->and($game->fresh()->away_score)->toBe(1);
});

it('validation fails when final mlb scores are reconstructable but missing', function () {
    $game = mlbReconciliationGame(homeScore: null, awayScore: null, status: 'STATUS_FINAL');
    mlbTeamRuns($game, homeRuns: 7, awayRuns: 3);
    mlbCompleteFinalArtifacts($game);

    $result = app(FinalizedDataCompletenessCheck::class)->run('mlb', config('validation.sports.mlb'));
    $sample = collect($result['metadata']['sample_games'])->firstWhere('game_id', $game->id);

    expect($result['status'])->toBe('failing')
        ->and($result['metadata']['reconstructable_missing_game_scores'])->toBe(1)
        ->and($result['metadata']['score_conflicts_with_team_stats'])->toBe(0)
        ->and($sample['reasons'])->toContain('reconstructable_missing_game_score');
});

it('validation reports but does not fail when missing scores are not reconstructable yet', function () {
    $game = mlbReconciliationGame(homeScore: null, awayScore: null, status: 'STATUS_FINAL');
    mlbTeamRuns($game, homeRuns: 7, awayRuns: null);
    mlbCompleteFinalArtifacts($game);

    $result = app(FinalizedDataCompletenessCheck::class)->run('mlb', config('validation.sports.mlb'));

    expect($result['status'])->toBe('warning')
        ->and($result['metadata']['reconstructable_missing_game_scores'])->toBe(0)
        ->and($result['metadata']['non_reconstructable_missing_game_scores'])->toBe(1);
});

function mlbReconciliationGame(
    ?int $homeScore,
    ?int $awayScore,
    string $status,
    int $season = 2026,
): Game {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    return Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => $season,
        'season_type' => (string) config('mlb.season.types.regular', 2),
        'status' => $status,
        'game_date' => now()->copy()->subDay()->toDateString(),
        'home_score' => $homeScore,
        'away_score' => $awayScore,
    ]);
}

function mlbTeamRuns(Game $game, ?int $homeRuns, ?int $awayRuns): void
{
    TeamStat::factory()->create([
        'game_id' => $game->id,
        'team_id' => $game->home_team_id,
        'team_type' => 'home',
        'runs' => $homeRuns,
    ]);

    TeamStat::factory()->create([
        'game_id' => $game->id,
        'team_id' => $game->away_team_id,
        'team_type' => 'away',
        'runs' => $awayRuns,
    ]);
}

function mlbCompleteFinalArtifacts(Game $game): void
{
    PlayerStat::factory()->create([
        'game_id' => $game->id,
        'player_id' => Player::factory()->create([
            'team_id' => $game->home_team_id,
        ])->id,
        'team_id' => $game->home_team_id,
    ]);

    Play::query()->create([
        'game_id' => $game->id,
        'espn_play_id' => 'test-'.$game->id,
        'sequence_number' => 1,
        'inning' => 9,
        'inning_half' => 'bottom',
        'play_type' => 'end',
        'play_text' => 'Final',
        'home_score' => $game->home_score,
        'away_score' => $game->away_score,
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'season' => $game->season,
        'season_type' => (string) $game->season_type,
        'predicted_spread' => 1.2,
        'predicted_total' => 8.5,
        'win_probability' => 0.56,
        'confidence_score' => 60,
        'graded_at' => now(),
    ]);
}
