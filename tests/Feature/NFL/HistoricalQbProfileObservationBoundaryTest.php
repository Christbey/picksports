<?php

use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Services\ESPN\NFL\EspnService;
use App\Services\NFL\NflPredictionProfileConfigurator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function historicalProfileQbFixtureGame(): Game
{
    $suffix = (string) random_int(100000, 999999);

    $homeTeam = Team::query()->create([
        'espn_id' => "HOME_TEST_{$suffix}",
        'abbreviation' => "H{$suffix}",
        'location' => 'Home City',
        'name' => 'Home Team',
    ]);

    $awayTeam = Team::query()->create([
        'espn_id' => "AWAY_TEST_{$suffix}",
        'abbreviation' => "A{$suffix}",
        'location' => 'Away City',
        'name' => 'Away Team',
    ]);

    $game = Game::query()->create([
        'espn_event_id' => "9{$suffix}",
        'espn_uid' => "uid-9{$suffix}",
        'season' => 2025,
        'week' => 10,
        'season_type' => 'regular',
        'game_date' => '2025-10-15',
        'game_time' => '19:20:00',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);

    EloRating::query()->create([
        'team_id' => $homeTeam->id,
        'game_id' => null,
        'season' => 2025,
        'week' => 9,
        'date' => '2025-10-10',
        'elo_rating' => 1575.0,
        'elo_change' => 0.0,
    ]);

    EloRating::query()->create([
        'team_id' => $awayTeam->id,
        'game_id' => null,
        'season' => 2025,
        'week' => 9,
        'date' => '2025-10-10',
        'elo_rating' => 1490.0,
        'elo_change' => 0.0,
    ]);

    return $game->fresh(['homeTeam', 'awayTeam']);
}

it('requires more than a historical QB profile name to attest late imported NFLverse identity', function (?string $sourceTimestamp, string $profile, bool $expectedVerified, string $mode = 'mutable') {
    Http::preventStrayRequests();
    $this->travelTo('2026-11-28 12:00:00');
    try {
        $game = historicalProfileQbFixtureGame();
        $home = $game->homeTeam->abbreviation;
        $away = $game->awayTeam->abbreviation;
        $game->update([
            'season' => 2026,
            'week' => 1,
            'game_date' => '2026-09-13',
            'status' => 'STATUS_FINAL',
            'home_score' => 21, 'away_score' => 14,
        ]);

        $priorGame = Game::query()->create([
            'espn_event_id' => 'nflverse-qb-prior-'.$game->id,
            'espn_uid' => 'nflverse-qb-prior-uid-'.$game->id,
            'nflverse_game_id' => '2025_18_'.$away.'_'.$home,
            'season' => 2025,
            'week' => 18,
            'season_type' => '2',
            'game_date' => '2026-01-04',
            'game_time' => '12:00:00',
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'home_score' => 31,
            'away_score' => 17,
            'status' => 'STATUS_FINAL',
            'neutral_site' => false,
        ]);

        DB::table('nflverse_rosters')->insert([
            [
                'nflverse_roster_key' => hash('sha256', 'home-qb-roster'),
                'season' => 2026,
                'team_id' => $game->home_team_id,
                'team' => $home,
                'gsis_id' => '00-HOMEQB',
                'full_name' => 'NFLVerse Home QB',
                'position' => 'QB',
                'years_exp' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nflverse_roster_key' => hash('sha256', 'away-qb-roster'),
                'season' => 2026,
                'team_id' => $game->away_team_id,
                'team' => $away,
                'gsis_id' => '00-AWAYQB',
                'full_name' => 'NFLVerse Away QB',
                'position' => 'QB',
                'years_exp' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('nflverse_depth_charts')->insert([
            [
                'nflverse_depth_chart_key' => hash('sha256', 'home-qb-depth'),
                'season' => 2026,
                'team_id' => $game->home_team_id,
                'team' => $home,
                'gsis_id' => '00-HOMEQB',
                'full_name' => 'NFLVerse Home QB',
                'position' => 'QB',
                'depth_position' => 'QB',
                'formation' => 'Offense',
                'depth_rank' => 1,
                'source_updated_at' => $sourceTimestamp,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nflverse_depth_chart_key' => hash('sha256', 'away-qb-depth'),
                'season' => 2026,
                'team_id' => $game->away_team_id,
                'team' => $away,
                'gsis_id' => '00-AWAYQB',
                'full_name' => 'NFLVerse Away QB',
                'position' => 'QB',
                'depth_position' => 'QB',
                'formation' => 'Offense',
                'depth_rank' => 1,
                'source_updated_at' => $sourceTimestamp,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('nflverse_weekly_player_stats')->insert([
            [
                'nflverse_weekly_stat_key' => hash('sha256', 'home-qb-weekly'),
                'nfl_game_id' => $priorGame->id,
                'nflverse_game_id' => $priorGame->nflverse_game_id,
                'season' => 2025,
                'week' => 18,
                'season_type' => 'REG',
                'team_id' => $game->home_team_id,
                'team' => $home,
                'opponent_team_id' => $game->away_team_id,
                'opponent_team' => $away,
                'player_id' => '00-HOMEQB',
                'player_display_name' => 'NFLVerse Home QB',
                'position' => 'QB',
                'passing_attempts' => 30,
                'passing_yards' => 300,
                'passing_touchdowns' => 3,
                'interceptions_thrown' => 0,
                'rushing_yards' => 24,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nflverse_weekly_stat_key' => hash('sha256', 'away-qb-weekly'),
                'nfl_game_id' => $priorGame->id,
                'nflverse_game_id' => $priorGame->nflverse_game_id,
                'season' => 2025,
                'week' => 18,
                'season_type' => 'REG',
                'team_id' => $game->away_team_id,
                'team' => $away,
                'opponent_team_id' => $game->home_team_id,
                'opponent_team' => $home,
                'player_id' => '00-AWAYQB',
                'player_display_name' => 'NFLVerse Away QB',
                'position' => 'QB',
                'passing_attempts' => 30,
                'passing_yards' => 150,
                'passing_touchdowns' => 0,
                'interceptions_thrown' => 2,
                'rushing_yards' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $observedSnapshots = [];
        if ($mode === 'observed-identity') {
            $this->travelTo('2026-09-12 12:00:00');
            $depthPayloads = [];
            foreach ([$game->homeTeam, $game->awayTeam] as $index => $team) {
                $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => (string) (8101 + $index), 'experience' => 5, 'position' => 'QB']);
                PlayerStat::factory()->create(['player_id' => $player->id, 'team_id' => $team->id, 'game_id' => $priorGame->id,
                    'passing_attempts' => 30, 'passing_yards' => $index === 0 ? 300 : 150, 'passing_touchdowns' => $index === 0 ? 3 : 0,
                    'interceptions_thrown' => $index === 0 ? 0 : 2, 'sacks_taken' => 0, 'rushing_yards' => $index === 0 ? 24 : 0]);
                $payload = ['lastUpdated' => '2026-09-12T11:00:00Z', 'items' => [['id' => 'offense', 'name' => 'Offense', 'positions' => [
                    'qb' => ['position' => ['id' => '1', 'name' => 'Quarterback', 'displayName' => 'Quarterback', 'abbreviation' => 'QB'],
                        'athletes' => [['slot' => 1, 'rank' => 1, 'athlete' => ['id' => $player->espn_id]]]],
                ]]]];
                $depthPayloads[] = $payload;
            }
            Http::fake(['*' => Http::sequence()->push($depthPayloads[0])->push($depthPayloads[1])]);
            foreach ([$game->homeTeam, $game->awayTeam] as $team) {
                expect((new SyncTeamDepthCharts(app(EspnService::class)))->execute((string) $team->espn_id, 2026))->toBe(1);
                $written = DepthChartSnapshot::query()->where('team_id', $team->id)->latest('id')->firstOrFail();
                expect($written->payload_hash)->toBeString()->toHaveLength(64);
                $observedSnapshots[$team->id] = ['id' => $written->id, 'uuid' => $written->snapshot_uuid, 'hash' => $written->payload_hash];
            }
            expect(DepthChartSnapshot::query()->count())->toBe(2);
            expect(DepthChartSnapshot::query()->max('observed_at'))->toBeLessThan('2026-09-13 00:00:00');
            $this->travelTo('2026-11-28 12:00:00');
        } elseif ($mode === 'no-sample') {
            DB::table('nflverse_weekly_player_stats')->delete();
        }

        $profiles = app(NflPredictionProfileConfigurator::class);
        $generator = app(GeneratePredictionFromHistoricalElo::class);
        $profiles->withProfile('elo-only', fn () => $generator->execute($game->fresh(['homeTeam', 'awayTeam'])));
        $elo = Prediction::query()->where('game_id', $game->id)->sole()->getAttributes();
        $profiles->withProfile($profile, fn () => $generator->execute($game->fresh(['homeTeam', 'awayTeam'])));
        $prediction = Prediction::query()->where('game_id', $game->id)->sole();
        $snapshot = PredictionFeatureSnapshot::query()->where('sport', 'nfl')->where('game_id', $game->id)
            ->where('lineage_metadata->historical_profile', $profile)->latest('id')->firstOrFail();
        $trace = getenv('REVIEW_NFL_HISTORICAL_PROFILE_TRACE');
        if (is_string($trace) && $trace !== '') {
            file_put_contents($trace, json_encode(['profile' => $profile, 'mode' => $mode, 'observed_snapshots' => $observedSnapshots, 'source_timestamp' => $sourceTimestamp,
                'game' => $game->fresh()->getAttributes(), 'depth_rows' => DB::table('nflverse_depth_charts')->get()->all(),
                'elo_prediction' => $elo, 'prediction' => $prediction->getAttributes(), 'snapshot' => $snapshot->getAttributes()], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        if ($profile === 'qb-form' && $mode !== 'no-sample') {
            // Require a real consumed numeric branch, not merely a metadata label.
            expect(data_get($prediction->model_metadata, 'qb_form.applied'))->toBeTrue();
            expect(data_get($prediction->model_metadata, $mode === 'observed-identity' ? 'qb_form.home.projected_from_depth_chart' : 'qb_form.home.projected_from_nflverse_depth_chart'))->toBeTrue();
            if ($mode === 'observed-identity') {
                foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $side => $teamId) {
                    $link = data_get($prediction->model_metadata, 'qb_form.'.$side.'.depth_chart_game_link');
                    expect($link['source'])->toBe('append_only_snapshot')
                        ->and($link['snapshot_id'])->toBe($observedSnapshots[$teamId]['id'])
                        ->and($link['snapshot_uuid'])->toBe($observedSnapshots[$teamId]['uuid']);
                    $selected = DepthChartSnapshot::query()->findOrFail($link['snapshot_id']);
                    expect($selected->payload_hash)->toBe($observedSnapshots[$teamId]['hash']);
                }
            }
            expect((float) $prediction->predicted_spread)->not->toBe((float) $elo['predicted_spread']);
        }
        if ($mode === 'no-sample') {
            expect(data_get($prediction->model_metadata, 'qb_form.applied'))->toBeFalse();
        }
        expect((bool) data_get($snapshot->lineage_metadata, 'point_in_time_verified'))->toBe($expectedVerified);
        if (! $expectedVerified) {
            expect($snapshot->availability_status)->not->toBe('verified_reconstruction');
        }
    } finally {
        $this->travelBack();
    }
})->with([
    'late NULL-timestamp QB source does not prove pregame availability' => [null, 'qb-form', false],
    'late backdated QB source does not prove observation before kickoff' => ['2026-07-20 12:00:00', 'qb-form', false],
    'existing elo-only ordered reconstruction contract remains diagnostic positive' => [null, 'elo-only', true],
    'real observed depth identity does not certify mutable form observations' => [null, 'qb-form', false, 'observed-identity'],
    'nonapplied QB form retains existing reconstruction contract' => [null, 'qb-form', true, 'no-sample'],
]);
