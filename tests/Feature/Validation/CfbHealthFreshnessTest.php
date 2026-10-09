<?php

use App\Actions\Validation\Checks\CurrentDayGameDataFreshnessCheck;
use App\Actions\Validation\Checks\PipelineOrderCheck;
use App\Actions\Validation\Checks\PredictionCompletenessCheck;
use App\Actions\Validation\Checks\UpcomingGameReadinessCheck;
use App\Actions\Validation\Contracts\ValidationCheck;
use App\Actions\Validation\SportValidator;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\CFB\TeamStat;
use App\Models\Healthcheck;
use App\Models\SportEvent;
use App\Models\ValidationRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now('America/Chicago')->setDate(2026, 10, 8)->setTime(18, 0));
    config()->set('prediction_lifecycle.canonical_pipeline.cfb', true);
    config()->set('ai.features.validation_review_summary.enabled', false);
});

afterEach(function () {
    $this->travelBack();
});

function cfbHealthGame(string $subdivision = 'FBS', int $days = 1): Game
{
    $teams = Team::factory()->count(2)->create();
    foreach ($teams as $team) {
        TeamSeasonAffiliation::create(['team_id' => $team->id, 'season' => 2026,
            'subdivision' => $subdivision, 'source' => 'cfbd_fbs_membership']);
        if ($subdivision === 'FBS') {
            DB::table('cfb_team_metrics')->insert(['team_id' => $team->id, 'season' => 2026,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $startsAt = now()->addDays($days);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => $startsAt]);

    return Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'sport_event_id' => $event->id, 'season' => 2026, 'season_type' => 2,
        'game_date' => $startsAt->toDateString(), 'game_time' => $startsAt->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED', 'odds_data' => null, 'odds_api_event_id' => null]);
}

function cfbHealthForecast(Game $game, string $state = 'published', int $ageHours = 0): CanonicalPrediction
{
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $game->sport_event_id,
        'phase' => 'pregame', 'publication_state' => $state, 'generated_at' => now()->subHours($ageHours),
        'published_at' => $state === 'published' ? now()->subHours($ageHours) : null]);
    $prediction->markets()->create(['market_type' => 'spread', 'selection' => 'home', 'projected_line' => -7]);

    return $prediction;
}

it('checks the published canonical forecast instead of a missing legacy row', function () {
    $game = cfbHealthGame();
    cfbHealthForecast($game);
    $profile = config('validation.sports.cfb');
    $prediction = app(PredictionCompletenessCheck::class)->run('cfb', $profile);
    $readiness = app(UpcomingGameReadinessCheck::class)->run('cfb', $profile);
    expect($prediction['status'])->toBe('passing')
        ->and($prediction['metadata']['prediction_source'])->toBe('canonical')
        ->and($readiness['metadata']['games_missing_predictions'])->toBe(0);
});

it('does not count a draft forecast as published coverage', function () {
    $game = cfbHealthGame();
    cfbHealthForecast($game, 'draft');
    $result = app(PredictionCompletenessCheck::class)->run('cfb', config('validation.sports.cfb'));
    expect($result['status'])->toBe('failing')->and($result['metadata']['games_missing_predictions'])->toBe(1);
});

it('reports old published forecasts as stale without calling them missing', function () {
    $game = cfbHealthGame();
    cfbHealthForecast($game, 'published', 48);
    $result = app(PredictionCompletenessCheck::class)->run('cfb', config('validation.sports.cfb'));
    expect($result['status'])->toBe('failing')->and($result['metadata']['games_missing_predictions'])->toBe(0)
        ->and($result['metadata']['games_with_stale_predictions'])->toBe(1);
});

it('exempts explicit non FBS games but still reports unknown membership', function () {
    cfbHealthGame('NON_FBS');
    $unknown = cfbHealthGame('UNKNOWN');
    $profile = config('validation.sports.cfb');
    $readiness = app(UpcomingGameReadinessCheck::class)->run('cfb', $profile);
    $prediction = app(PredictionCompletenessCheck::class)->run('cfb', $profile);
    expect($readiness['metadata']['upcoming_games'])->toBe(1)
        ->and($readiness['metadata']['games_missing_team_metrics'])->toBe(1)
        ->and($prediction['metadata']['sample_game_ids'])->toBe([$unknown->id]);
});

it('does not flag unchanged distant schedules but flags stale near kickoff records', function () {
    $near = cfbHealthGame('FBS', 0);
    $far = cfbHealthGame('FBS', 4);
    foreach ([$near, $far] as $game) {
        DB::table('cfb_games')->where('id', $game->id)->update(['updated_at' => now()->subDays(2)]);
        cfbHealthForecast($game);
    }
    $result = app(UpcomingGameReadinessCheck::class)->run('cfb', config('validation.sports.cfb'));
    expect($result['metadata']['stale_game_rows'])->toBe(1)
        ->and($result['metadata']['sample_game_ids'])->toContain($near->id)->not->toContain($far->id);
});

it('does not demand timestamp refreshes for complete final games', function () {
    $game = cfbHealthGame('FBS', 0);
    $game->update(['status' => 'STATUS_FINAL', 'game_time' => '00:04:00']);
    DB::table('cfb_games')->where('id', $game->id)->update(['updated_at' => now()->subHours(20)]);
    foreach ([$game->home_team_id => 'home', $game->away_team_id => 'away'] as $teamId => $side) {
        TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $teamId, 'team_type' => $side]);
    }
    $player = Player::factory()->create(['espn_id' => 'freshness-player', 'team_id' => $game->home_team_id]);
    PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $player->id, 'team_id' => $game->home_team_id]);
    Play::factory()->create(['espn_play_id' => 'freshness-play', 'game_id' => $game->id, 'possession_team_id' => $game->home_team_id]);
    $result = app(CurrentDayGameDataFreshnessCheck::class)->run('cfb', config('validation.sports.cfb'));
    expect($result['status'])->toBe('passing')->and($result['metadata']['stale_games'])->toBe(0);
    PlayerStat::where('game_id', $game->id)->delete();
    $result = app(CurrentDayGameDataFreshnessCheck::class)->run('cfb', config('validation.sports.cfb'));
    expect($result['status'])->toBe('failing')->and($result['metadata']['final_games_missing_player_stats'])->toBe(1);
});

it('records a failed check and continues saving the remaining health findings', function () {
    $validator = new SportValidator;
    $checks = [new class implements ValidationCheck
    {
        public function run(string $sport, array $profile): ?array
        {
            throw new RuntimeException('Provider validation unavailable');
        }
    }, new class implements ValidationCheck
    {
        public function run(string $sport, array $profile): ?array
        {
            return ['check_type' => 'validation_after_error', 'status' => 'passing', 'message' => 'Next check completed.'];
        }
    }];
    (new ReflectionProperty(SportValidator::class, 'fullChecks'))->setValue($validator, $checks);
    app()->instance(SportValidator::class, $validator);
    $old = ValidationRun::create(['command_name' => 'healthcheck:validate-data', 'scope' => 'sport:cfb',
        'status' => 'running', 'started_at' => now()->subDays(2)]);
    $this->artisan('healthcheck:validate-data', ['--sport' => 'cfb', '--skip-ai-summary' => true, '--skip-alerts' => true])->assertFailed();
    expect($old->refresh()->status)->toBe('interrupted')
        ->and(ValidationRun::latest('id')->first()->status)->toBe('failing')
        ->and(Healthcheck::where('check_type', 'validation_after_error')->exists())->toBeTrue();
});

it('skips recovery only when a completed audit is recent', function () {
    ValidationRun::create(['command_name' => 'healthcheck:validate-data', 'scope' => 'sport:cfb',
        'status' => 'passing', 'started_at' => now()->subMinutes(30), 'completed_at' => now()->subMinutes(29)]);
    $this->artisan('healthcheck:validate-data', ['--sport' => 'cfb', '--if-stale' => 120])
        ->expectsOutput('Recent completed validation is available.')->assertSuccessful();
    expect(ValidationRun::count())->toBe(1);
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'healthcheck:validate-data --sport=cfb --if-stale'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('55 * * * *');
});

it('checks actual canonical source ordering without requiring legacy command heartbeats', function () {
    $game = cfbHealthGame();
    cfbHealthForecast($game);
    cfbHealthGame('NON_FBS');
    $check = app(PipelineOrderCheck::class);
    $profile = config('validation.sports.cfb');
    expect($check->run('cfb', $profile)['status'])->toBe('passing');
    $this->travel(15)->minutes();
    DB::table('cfb_team_metrics')->where('team_id', $game->home_team_id)->update(['updated_at' => now()]);
    $result = $check->run('cfb', $profile);
    expect($result['status'])->toBe('failing')->and($result['metadata']['checked_active_games'])->toBe(1)
        ->and($result['metadata']['violations'][0]['reason'])->toBe('metrics_newer_than_forecast');
});
