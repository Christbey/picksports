<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\PlayerInjurySnapshotEntry;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\BettingRecommendations\NflPropSeasonSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->opponent = Team::factory()->create();
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'position' => 'QB', 'full_name' => 'Caleb Williams']);
    $this->target = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-22', 'game_time' => '23:15:00']);
    $this->prop = PlayerProp::create(['game_id' => $this->target->id, 'player_id' => $this->player->id, 'player_name' => $this->player->full_name,
        'market' => 'player_pass_yds', 'line' => 240.5, 'bookmaker' => 'test', 'recommended_side' => 'Over']);
    $this->past = fn ($date) => Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => $date, 'game_time' => '17:00:00', 'home_qb_name' => 'Backup QB']);
    $this->report = function ($observed, $status = 'Out') {
        $snapshot = PlayerInjurySnapshot::create(['snapshot_uuid' => Str::uuid()->toString(), 'team_id' => $this->team->id,
            'espn_team_id' => $this->team->espn_id, 'observed_at' => $observed, 'payload_hash' => hash('sha256', $observed), 'entry_count' => $status === null ? 0 : 1]);
        if ($status !== null) {
            PlayerInjurySnapshotEntry::create(['snapshot_id' => $snapshot->id, 'player_id' => $this->player->id,
                'espn_athlete_id' => $this->player->espn_id, 'injury_key' => 'ankle', 'status' => $status, 'observed_at' => $observed]);
        }
    };
    $this->summary = fn () => app(NflPropSeasonSummary::class)->forProps(new Collection([$this->prop]))[$this->prop->id];
});

it('separates injured absences from unknown absences and null reported stats without adding zero performances', function () {
    $played = ($this->past)('2026-09-13');
    PlayerStat::factory()->create(['game_id' => $played->id, 'player_id' => $this->player->id, 'team_id' => $this->team->id, 'passing_yards' => 300]);
    $injured = ($this->past)('2026-09-20');
    ($this->report)('2026-09-19 17:00:00');
    ($this->past)('2026-10-04'); // Too old to extend the September report.
    $missing = ($this->past)('2026-10-11');
    PlayerStat::factory()->create(['game_id' => $missing->id, 'player_id' => $this->player->id, 'team_id' => $this->team->id, 'passing_yards' => null]);
    $summary = ($this->summary)();
    expect($summary['average'])->toBe(300.0)->and($summary['games'])->toBe(1)
        ->and($summary['missing_stat_games'])->toBe(1)
        ->and($summary['participation']['team_games'])->toBe(4)
        ->and($summary['participation']['recorded_games'])->toBe(2)
        ->and($summary['participation']['confirmed_unavailable_game_ids'])->toBe([$injured->id])
        ->and($summary['participation']['unexplained_absence_games'])->toBe(1)
        ->and($summary['participation']['other_qb_started_games'])->toBe(1);
});

it('uses the last full report and never later or current injuries to explain an earlier absence', function () {
    ($this->past)('2026-09-20');
    ($this->report)('2026-09-18 17:00:00');
    ($this->report)('2026-09-19 17:00:00', null); // Cleared from the full report.
    ($this->report)('2026-09-20 18:00:00'); // After kickoff: unavailable as pregame evidence.
    $summary = ($this->summary)();
    expect($summary['participation']['confirmed_unavailable_games'])->toBe(0)
        ->and($summary['participation']['unexplained_absence_games'])->toBe(1)
        ->and($summary['games'])->toBe(0)->and($summary['average'])->toBeNull();
});

it('does not read a same-day local injury observation after kickoff as an earlier UTC report', function () {
    ($this->past)('2026-09-20'); // 17:00 UTC = noon Chicago.
    ($this->report)('2026-09-20 12:30:00'); // 17:30 UTC, after kickoff.
    $summary = ($this->summary)();
    expect($summary['participation']['confirmed_unavailable_games'])->toBe(0)
        ->and($summary['participation']['unexplained_absence_games'])->toBe(1);
});

it('rejects injury source evidence published after the historical kickoff', function () {
    ($this->past)('2026-09-20');
    ($this->report)('2026-09-19 10:00:00');
    PlayerInjurySnapshotEntry::query()->update(['source_updated_at' => '2026-09-20 13:00:00']);
    $summary = ($this->summary)();
    expect($summary['participation']['confirmed_unavailable_games'])->toBe(0)
        ->and($summary['participation']['unexplained_absence_games'])->toBe(1);
});
