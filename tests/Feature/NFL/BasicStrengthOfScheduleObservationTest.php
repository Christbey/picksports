<?php

use App\Actions\NFL\CalculateTeamMetrics;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-11-20 12:00:00');
    config(['nfl.predictions.true_epa.custom_epa_quarantined' => true]);
});
afterEach(fn () => $this->travelBack());

it('uses observed regular season opponent results for regular basic schedule strength', function (string $case, int $targetScore, int $opponentScore, ?array $extra, float $expected) {
    $team = Team::factory()->create(['elo_rating' => 1500]);
    $opponent = Team::factory()->create(['elo_rating' => 1500]);
    $other = Team::factory()->create(['elo_rating' => 1500]);
    $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $opponent->id,
        'season' => 2026, 'season_type' => 2, 'game_date' => '2026-10-01', 'game_time' => '18:00:00',
        'status' => 'STATUS_FINAL', 'home_score' => $targetScore, 'away_score' => $opponentScore]);
    foreach ([$team, $opponent] as $participant) {
        TeamStat::factory()->create(['team_id' => $participant->id, 'game_id' => $game->id,
            'total_yards' => 300, 'passing_yards' => 200, 'rushing_yards' => 100, 'interceptions' => 0, 'fumbles_lost' => 0]);
    }
    $action = app(CalculateTeamMetrics::class);
    $baseline = $action->execute($team, 2026, 2);
    expect($baseline)->not->toBeNull();
    $baselineRow = $baseline->fresh()->getAttributes();
    $secondary = $extra === null ? null : Game::factory()->create(['home_team_id' => $opponent->id, 'away_team_id' => $other->id,
        'season' => 2026, 'game_date' => '2026-10-02', 'game_time' => '18:00:00', 'status' => 'STATUS_FINAL', ...$extra]);
    $metric = $action->execute($team, 2026, 2);
    $actual = $metric?->fresh();
    $trace = getenv('REVIEW_NFL_BASIC_SOS_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['case' => $case, 'primary' => $game->fresh()->getAttributes(),
            'secondary' => $secondary?->fresh()->getAttributes(), 'baseline_metric' => $baselineRow,
            'actual_metric' => $actual?->getAttributes()], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    expect($actual)->not->toBeNull();
    expect($actual->id)->toBe($baseline->id);
    expect((float) $actual->strength_of_schedule_basic)->toBe($expected);
    expect($actual->games_played)->toBe(1)->and((string) $actual->season_type)->toBe('2');
    expect($actual->points_per_game)->toBe($baseline->points_per_game);
})->with([
    'no extra opponent game' => ['absent', 28, 21, null, 0.0],
    'missing away score cannot add opponent win' => ['missing-away', 28, 21, ['season_type' => 2, 'home_score' => 10, 'away_score' => null], 0.0],
    'missing home score cannot add opponent loss' => ['missing-home', 21, 28, ['season_type' => 2, 'home_score' => null, 'away_score' => 10], 1.0],
    'preseason result excluded from regular SOS' => ['preseason', 28, 21, ['season_type' => 1, 'home_score' => 10, 'away_score' => 0], 0.0],
    'postseason result excluded from regular SOS' => ['postseason', 28, 21, ['season_type' => 3, 'home_score' => 10, 'away_score' => 0], 0.0],
    'recorded opponent shutout win counts' => ['zero-away', 28, 21, ['season_type' => 2, 'home_score' => 10, 'away_score' => 0], 0.5],
    'recorded opponent shutout loss counts' => ['zero-home', 21, 28, ['season_type' => 2, 'home_score' => 0, 'away_score' => 10], 0.5],
    'observed complete regular win counts' => ['complete', 28, 21, ['season_type' => 2, 'home_score' => 14, 'away_score' => 7], 0.5],
    'existing tie exclusion policy retained' => ['tie', 28, 21, ['season_type' => 2, 'home_score' => 0, 'away_score' => 0], 0.0],
]);
