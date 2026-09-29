<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetricSnapshot;

function completeNflFuturesSchedule(int $season, string $capturedAt): array
{
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
                TeamMetricSnapshot::query()->create([
                    'snapshot_key' => sha1("fixture-{$season}-{$team->id}"), 'team_id' => $team->id, 'season' => $season,
                    'wins' => 0, 'losses' => 0, 'predictive_rating' => 0, 'future_strength_of_schedule' => 1500,
                    'recent_form_rating' => 0, 'injury_total_adjustment' => 0,
                    'calculation_date' => substr($capturedAt, 0, 10), 'captured_at' => $capturedAt,
                ]);
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
