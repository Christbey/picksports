<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Mail\DailyPredictionsDigestMail;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\Player as CfbPlayer;
use App\Models\CFB\Team as CfbTeam;
use App\Models\CfbdTeamMapping;
use App\Models\DailyDigestSend;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\LivePredictionSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NFL\Player;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\AlertService;
use App\Services\BettingAlertEligibility;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\CFB\CfbPlayerEvidenceService;
use App\Services\CFB\CfbPlayerMatcher;
use App\Services\DailyDigestService;
use App\Services\NotificationTemplateDefaultService;
use App\Services\Predictions\LivePredictionStore;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Settings\CfbdTeamMappingIndexDataService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

it('eager loads the latest twenty invitations per group with bounded queries', function () {
    $owner = User::factory()->create();
    foreach (range(1, 3) as $number) {
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Pool '.$number, 'type' => 'bracket_pool', 'sport' => 'cbb', 'season' => 2026]);
        foreach (range(1, 23) as $offset) {
            GroupInvitation::create(['group_id' => $group->id, 'invited_by' => $owner->id,
                'email' => $offset.'@example.test', 'created_at' => now()->addSeconds($offset)]);
        }
    }
    DB::enableQueryLog();
    $groups = Group::with(['invitations' => fn ($query) => $query->latest()->limit(20)])->get();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($groups)->toHaveCount(3)
        ->and($groups->every(fn ($group) => $group->invitations->count() === 20))->toBeTrue()
        ->and(count($queries))->toBe(2);
});

it('shares mapping option and suggestion reads without dirtying the source mapping', function () {
    CfbTeam::factory()->create(['school' => 'Example State', 'abbreviation' => 'EXS']);
    CfbdTeamMapping::create(['cfbd_team_id' => 9, 'cfbd_team_name' => 'Example State', 'sport' => 'americanfootball_ncaaf']);
    $service = app(CfbdTeamMappingIndexDataService::class);
    DB::enableQueryLog();
    $rows = $service->mappings('americanfootball_ncaaf', 'all');
    $service->espnTeams(['teamModel' => CfbTeam::class, 'teamField' => 'school']);
    $counts = $service->stats('americanfootball_ncaaf');
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($rows->items()[0]['suggested_espn_team_name'])->toBe('Example State')
        ->and($counts)->toBe(['total' => 1, 'mapped' => 0, 'unmapped' => 1])
        ->and($queries->filter(fn ($row) => str_contains($row['query'], 'from "cfb_teams"')))->toHaveCount(1)
        ->and(CfbdTeamMapping::first()->getAttributes())->not->toHaveKey('suggested_espn_team_name');
});

it('reads build provenance from configuration rather than application env access', function () {
    config(['app.code_version' => ' release-abc ']);
    expect(app(ModelRunRecorder::class)->codeVersion())->toBe('release-abc');
});

it('reuses cutoff-specific CFB evidence only inside the explicit batch', function () {
    $game = CfbGame::factory()->create(['home_team_id' => CfbTeam::factory(), 'away_team_id' => CfbTeam::factory()]);
    $service = app(CfbPlayerEvidenceService::class);
    DB::enableQueryLog();
    $service->withinBatch(function () use ($service, $game) {
        $first = $service->context($game, $game->home_team_id);
        expect($service->context($game, $game->home_team_id))->toBe($first);
        $service->quarterback($game, $game->home_team_id, context: $first);
        expect($service->context($game, $game->home_team_id, now()->subDay()))->not->toBe($first);
    });
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries->filter(fn ($row) => str_contains($row['query'], 'from "cfb_player_stats"')))->toHaveCount(2)
        ->and($queries->filter(fn ($row) => str_contains($row['query'], 'from "cfb_player_injuries"')))->toHaveCount(2);
    expect($service->context($game, $game->home_team_id))->not->toBe($service->context($game, $game->home_team_id));
});

it('matches a game roster once and continues rejecting ambiguous provider names', function () {
    $game = CfbGame::factory()->create(['home_team_id' => CfbTeam::factory(), 'away_team_id' => CfbTeam::factory()]);
    $player = CfbPlayer::factory()->create(['team_id' => $game->home_team_id, 'espn_id' => 'known-player', 'full_name' => 'Known Player']);
    CfbPlayer::factory()->create(['team_id' => $game->home_team_id, 'espn_id' => 'same-home', 'full_name' => 'Same Name']);
    CfbPlayer::factory()->create(['team_id' => $game->away_team_id, 'espn_id' => 'same-away', 'full_name' => 'Same Name']);
    $matcher = app(CfbPlayerMatcher::class);
    DB::enableQueryLog();
    expect($matcher->match($game, 'known-player')?->id)->toBe($player->id)
        ->and($matcher->match($game, 'Same Name'))->toBeNull()
        ->and($matcher->match($game, 'Missing Player'))->toBeNull();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
});

it('keeps live baseline validity out of Eloquent relationship serialization', function () {
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'status' => 'STATUS_IN_PROGRESS']);
    LivePredictionSnapshot::factory()->create(['game_id' => $game->id, 'status' => 'live',
        'baseline' => ['pinned' => true, 'prediction_source' => 'legacy'],
        'projection' => ['live_predicted_spread' => 3]]);
    $store = app(LivePredictionStore::class);
    $store->preload(collect([$game]));
    $snapshot = $store->latest($game);
    expect($snapshot->relationLoaded('baselineValidity'))->toBeFalse()
        ->and($snapshot->toArray())->not->toHaveKey('baseline_validity');
    DB::enableQueryLog();
    $store->fields($game);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toBe([]);
});

it('detects accidental lazy loading on persisted collections in the test environment', function () {
    expect(Model::preventsLazyLoading())->toBeTrue();
    User::factory()->count(2)->create();
    $users = User::query()->get();
    expect(fn () => $users->first()->alertPreference)->toThrow(LazyLoadingViolationException::class);
});

it('batches explicit research fact player inputs without dirtying the game', function () {
    $team = App\Models\NFL\Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $team->id]);
    $game = App\Models\NFL\Game::factory()->create(['home_team_id' => $team->id,
        'away_team_id' => App\Models\NFL\Team::factory()]);
    $calculator = new class extends GeneratePredictionFromHistoricalElo
    {
        protected function generate(App\Models\NFL\Game $game, bool $persist, ?string $profile = null): array|string|null
        {
            return ['outputs' => [], 'model_metadata' => []];
        }
    };
    DB::enableQueryLog();
    $calculator->preview($game, [['player_id' => $player->id], ['player_id' => $player->id]]);
    $calculator->preview($game);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($game->getDirty())->toBe([])
        ->and($game->getAttributes())->not->toHaveKey('research_availability')
        ->and($queries->filter(fn ($query) => str_contains($query['query'], 'from "nfl_players"')))->toHaveCount(1);
});

class CleanupDigestService extends DailyDigestService
{
    public function buildDigestForUser(User $user, ?CarbonInterface $now = null): ?array
    {
        return ['summary' => ['headline' => 'Fixture', 'intro' => 'Fixture', 'highlights' => []], 'predictions' => [], 'player_props' => []];
    }
}

it('filters previous digest sends in the recipient query instead of checking each user', function () {
    $this->travelTo(now()->setTime(12, 0));
    Mail::fake();
    config(['subscriptions.features.email_alerts_enabled' => true, 'alerts.daily_digest.enabled' => true]);
    $users = User::factory()->count(12)->create();
    DailyDigestSend::create(['user_id' => $users->first()->id, 'digest_date' => now()->toDateString(),
        'sent_at' => now(), 'predictions_count' => 0, 'player_props_count' => 0]);
    DB::enableQueryLog();
    expect(app(CleanupDigestService::class)->sendDueDigests())->toBe(11);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries->filter(fn ($query) => str_contains($query['query'], 'select exists') && str_contains($query['query'], 'daily_digest_sends')))->toHaveCount(0);
    Mail::assertSent(DailyPredictionsDigestMail::class, 11);
});

class CleanupAlertService extends AlertService
{
    protected function analyzeOpportunities(PredictionDeliveryContext $prediction): array
    {
        return [['expected_value' => 5.0, 'recommendation' => 'Fixture', 'selection' => []]];
    }
}

it('loads alert recipients and resolves even a missing template once across opportunities', function () {
    $user = User::factory()->create();
    UserAlertPreference::factory()->create(['user_id' => $user->id, 'enabled' => true, 'sports' => ['nba']]);
    $source = new Prediction;
    $context = new PredictionDeliveryContext($source, 'nba', new Game, null, null, null, null);
    $predictions = Mockery::mock(PredictionDeliverySource::class);
    $predictions->shouldReceive('forAlerts')->once()->with('nba')->andReturn(collect([$context, $context, $context]));
    $templates = Mockery::mock(NotificationTemplateDefaultService::class);
    $templates->shouldReceive('resolve')->once()->with('betting_value_alert')->andReturnNull();
    $eligibility = Mockery::mock(BettingAlertEligibility::class);
    $eligibility->shouldReceive('reason')->times(3)->andReturn('alerts_disabled');
    $service = new CleanupAlertService($templates, app(GameBettingRecommendationService::class), $predictions, $eligibility);
    DB::enableQueryLog();
    expect($service->checkForValueOpportunities('nba'))->toBe(0);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries->filter(fn ($query) => str_contains($query['query'], 'from "users"')))->toHaveCount(1);
});

it('keeps configured build provenance after a cache is loaded under different process environment', function () {
    $cache = tempnam(sys_get_temp_dir(), 'picksports-version-');
    $bootstrap = 'require '.var_export(base_path('vendor/autoload.php'), true).'; new Illuminate\\Foundation\\Application('.var_export(base_path(), true).'); ';
    $capture = $bootstrap.'$settings = require '.var_export(config_path('app.php'), true).'; '
        .'file_put_contents('.var_export($cache, true).', "<?php return ".var_export(["app" => ["code_version" => $settings["code_version"]]], true).";");';
    try {
        $process = new Process([PHP_BINARY, '-r', $capture], base_path(), [
            'SOURCE_VERSION' => '  ', 'APP_COMMIT_SHA' => ' commit-priority ', 'GIT_COMMIT' => 'third-choice',
        ]);
        $process->mustRun();
        $read = $bootstrap.'app()->instance("config", new Illuminate\\Config\\Repository(require '.var_export($cache, true).')); '
            .'echo (new App\\Services\\Predictions\\ModelRunRecorder)->codeVersion();';
        $process = new Process([PHP_BINARY, '-r', $read], base_path(), [
            'SOURCE_VERSION' => 'changed-after-cache', 'APP_COMMIT_SHA' => 'also-changed',
        ]);
        $process->mustRun();
        expect($process->getOutput())->toBe('commit-priority');
    } finally {
        unlink($cache);
    }
});
