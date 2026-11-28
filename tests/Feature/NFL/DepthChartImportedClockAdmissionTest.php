<?php

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameDepthChartLink;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\NFL\GameDepthChartLinkRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('does not promote a known missing imported kickoff through a cached event', function (bool $knownMissing, bool $mismatchedCanonical) {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T17:00:00Z'));
    $id = '2026_04_BUF_KC';
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-04', 'game_time' => '00:00:00',
        'nflverse_game_id' => $id, 'odds_data' => ['event_id' => 'nflverse:'.$id, 'commence_time' => $knownMissing ? null : '2026-10-04T00:00:00Z',
            'market_context' => ['source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2']]]);
    $start = CarbonImmutable::parse('2026-10-04T00:00:00Z')->setTimezone(config('app.timezone'));
    if ($mismatchedCanonical) {
        $start = $start->addHour();
    }
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'season' => 2026, 'season_type' => 2, 'starts_at' => $start]);
    $game->update(['sport_event_id' => $event->id]);
    $game->setRelation('sportEvent', $event);
    $snapshot = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $game->home_team_id,
        'espn_team_id' => $game->homeTeam->espn_id, 'season' => 2026, 'provider' => 'espn', 'observed_at' => now()->subHour(), 'payload_hash' => hash('sha256', 'synthetic-depth'), 'entry_count' => 0]);
    $payload = ['side' => 'home', 'game_id' => $game->id, 'team_id' => $game->home_team_id, 'snapshot_id' => $snapshot->id,
        'snapshot_uuid' => $snapshot->snapshot_uuid, 'as_of' => now()->toIso8601String()];
    $revision = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'nfl_predictions', 'prediction_id' => 7,
        'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_version' => 'synthetic', 'feature_version' => 'synthetic', 'blend_version' => 'synthetic',
        'features' => [], 'outputs' => [], 'market_context' => [], 'model_metadata' => ['depth_chart_game_link' => $payload],
        'feature_hash' => hash('sha256', 'synthetic'), 'generated_at' => now()]);
    app(GameDepthChartLinkRecorder::class)->record($game, $revision);
    expect(GameDepthChartLink::query()->count())->toBe(($knownMissing || $mismatchedCanonical) ? 0 : 1);
})->with([[true, false], [false, false], [false, true]]);
