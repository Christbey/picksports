<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Models\NFL\TeamMetricSnapshot;
use App\Services\NFL\TeamMetricSnapshotObservation;
use App\Services\NFL\TeamMetricSnapshotService;
use App\Support\Sports\ExplicitOffsetInstant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Create an authentic synthetic observation at its actual frozen fixture clock. */
function captureObservedNflMetricFixture(array $values, string $capturedAt): TeamMetricSnapshot
{
    $instant = ExplicitOffsetInstant::parse($capturedAt);
    if ($instant === null) {
        throw new InvalidArgumentException('Synthetic metric fixture requires an explicit-offset instant.');
    }
    $previousClock = Carbon::getTestNow();
    $previousTimezone = date_default_timezone_get();
    try {
        date_default_timezone_set((string) config('app.timezone'));
        Carbon::setTestNow($instant);
        $source = TeamMetric::query()->updateOrCreate(
            ['team_id' => $values['team_id'], 'season' => $values['season'], 'season_type' => '2'],
            array_intersect_key($values, array_flip([
                'wins', 'losses', 'predictive_rating', 'future_strength_of_schedule',
                'recent_form_rating', 'injury_total_adjustment', 'calculation_date',
            ])),
        );
        app(TeamMetricSnapshotService::class)->capture((int) $values['season'], $instant);
        $snapshot = TeamMetricSnapshot::query()->where('team_id', $source->team_id)
            ->where('season', $source->season)->latest('id')->firstOrFail();
        $lineage = TeamMetricSnapshotObservation::lineage($snapshot);
        if (($lineage['mode'] ?? null) !== 'current_capture'
            || ($lineage['observed_at'] ?? null) !== $instant->utc()->format('Y-m-d\TH:i:s.u\Z')) {
            throw new RuntimeException('Synthetic metric fixture did not capture its actual observation.');
        }

        return $snapshot;
    } finally {
        Carbon::setTestNow($previousClock);
        date_default_timezone_set($previousTimezone);
    }
}

function completeNflFuturesSchedule(int $season, string $capturedAt): array
{
    // Preserve existing calendar-only schedule fixtures at declared application-local midnight.
    $observationInstant = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $capturedAt)
        ? CarbonImmutable::parse($capturedAt, (string) config('app.timezone'))->startOfDay()->toIso8601String()
        : $capturedAt;
    $groups = [
        'AFC East' => ['BUF', 'MIA', 'NE', 'NYJ'], 'AFC North' => ['BAL', 'CIN', 'CLE', 'PIT'],
        'AFC South' => ['HOU', 'IND', 'JAX', 'TEN'], 'AFC West' => ['DEN', 'KC', 'LAC', 'LV'],
        'NFC East' => ['DAL', 'NYG', 'PHI', 'WSH'], 'NFC North' => ['CHI', 'DET', 'GB', 'MIN'],
        'NFC South' => ['ATL', 'CAR', 'NO', 'TB'], 'NFC West' => ['ARI', 'LAR', 'SEA', 'SF'],
    ];
    $teams = [];
    foreach ($groups as $group => $abbreviations) {
        [$conference, $division] = explode(' ', $group);
        foreach ($abbreviations as $abbreviation) {
            $team = Team::query()->where('abbreviation', $abbreviation)->first()
                ?? Team::factory()->create(compact('abbreviation', 'conference', 'division'));
            $teams[] = $team;
            if (! TeamMetricSnapshot::query()->where('team_id', $team->id)->where('season', $season)->exists()) {
                captureObservedNflMetricFixture([
                    'team_id' => $team->id, 'season' => $season,
                    'wins' => 0, 'losses' => 0, 'predictive_rating' => 0, 'future_strength_of_schedule' => 1500,
                    'recent_form_rating' => 0, 'injury_total_adjustment' => 0,
                    'calculation_date' => substr($capturedAt, 0, 10),
                ], $observationInstant);
            }
        }
    }
    $rotation = array_map(fn ($team) => $team->id, $teams);
    for ($week = 1; $week <= 17; $week++) {
        for ($i = 0; $i < 16; $i++) {
            Game::factory()->create([
                'season' => $season, 'season_type' => '2', 'week' => $week,
                'home_team_id' => $rotation[$i], 'away_team_id' => $rotation[31 - $i],
                'game_date' => "{$season}-09-01", 'status' => 'STATUS_SCHEDULED',
                'home_score' => null, 'away_score' => null, 'neutral_site' => false,
            ]);
        }
        $last = array_pop($rotation);
        array_splice($rotation, 1, 0, [$last]);
    }

    return $teams;
}
