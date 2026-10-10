<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\TeamStat;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Independently verified team statistics do not certify ESPN player boxes or plays. */
class CfbCfbdTeamBoxscoreSync
{
    public const COMPONENT = 'cfbd_team_boxscore';

    public const VERSION = 'cfbd-core-v1';

    public function __construct(private readonly CollegeFootballDataService $source) {}

    public function execute(Game $game): array
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $name = CfbdTeamMapping::where('cfbd_team_id', $game->homeTeam->cfbd_team_id)->value('cfbd_team_name')
            ?? $game->homeTeam->school;
        $query = ['year' => (int) $game->season, 'team' => $name, 'id' => (int) $game->espn_event_id];
        $payload = collect($this->source->get('/games/teams', $query))->firstWhere('id', (int) $game->espn_event_id);
        if (! is_array($payload)) {
            throw new RuntimeException('CFBD did not return the matching game.');
        }
        $attributes = $this->validate($game, $payload);
        $identity = $game->only(['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score']);
        $check = app(CfbSourceRevision::class)->record($game, self::COMPONENT, ['query' => $query, 'game' => $payload],
            ['state' => 'complete', 'discrepancies' => [], 'evidence' => ['identity' => $identity, 'team_stats' => $attributes,
                'policy' => 'verified_team_scores_and_yards_only_player_and_play_checks_retained']], 'cfbd', self::VERSION);
        DB::transaction(function () use ($game, $payload, $attributes, $identity, $check): void {
            $locked = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->validate($locked, $payload);
            foreach ($identity as $field => $value) {
                if ((string) $locked->{$field} !== (string) $value) {
                    throw new RuntimeException('Game identity changed while CFBD statistics were fetched.');
                }
            }
            foreach ($attributes as $teamId => $row) {
                TeamStat::updateOrCreate(['game_id' => $game->id, 'team_id' => $teamId], $row);
            }
            $check->update(['accepted_at' => now()]);
        });

        return ['state' => 'complete', 'game_id' => $game->id, 'source_hash' => $check->source_hash, 'provider' => 'cfbd'];
    }

    public function validate(Game $game, array $payload): array
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);
        if ($game->status !== 'STATUS_FINAL' || $game->home_score === null || $game->away_score === null
            || (string) ($payload['id'] ?? '') !== (string) $game->espn_event_id
            || count($payload['teams'] ?? []) !== 2) {
            throw new RuntimeException('CFBD team statistics require the matching final game and two teams.');
        }
        $rows = [];
        foreach (['home', 'away'] as $side) {
            $team = $game->{$side.'Team'};
            $box = collect($payload['teams'])->firstWhere('homeAway', $side);
            $expectedId = $team->cfbd_team_id ?: CfbdTeamMapping::whereIn('espn_team_name', array_filter([$team->school, $team->display_name]))
                ->value('cfbd_team_id');
            if (! $expectedId || ! preg_match('/^\d+$/', (string) ($box['teamId'] ?? ''))
                || (int) ($box['teamId'] ?? 0) !== (int) $expectedId
                || ! preg_match('/^\d+$/', (string) ($box['points'] ?? '')) || (int) $box['points'] !== (int) $game->{$side.'_score'}) {
                throw new RuntimeException('CFBD team identity or final score disagrees.');
            }
            $statistics = collect($box['stats'] ?? []);
            if ($statistics->pluck('category')->unique()->count() !== $statistics->count()) {
                throw new RuntimeException('Duplicate CFBD team-stat categories.');
            }
            $stats = $statistics->pluck('stat', 'category')->all();
            $number = static function (string $key, bool $required = false) use ($stats): ?int {
                $value = $stats[$key] ?? null;
                if ($value === null && ! $required) {
                    return null;
                }
                if (! preg_match('/^-?\d+$/', (string) $value)) {
                    throw new RuntimeException('Missing or invalid CFBD '.$key.'.');
                }
                if ((int) $value < 0 && ! in_array($key, ['totalYards', 'netPassingYards', 'rushingYards'], true)) {
                    throw new RuntimeException('Negative CFBD '.$key.'.');
                }

                return (int) $value;
            };
            $total = $number('totalYards', true);
            $passing = $number('netPassingYards', true);
            $rushing = $number('rushingYards', true);
            if ($total !== $passing + $rushing) {
                throw new RuntimeException('CFBD team yards do not reconcile.');
            }
            $pair = static function (string $key) use ($stats): array {
                if (! preg_match('/^(\d+)-(\d+)$/', (string) ($stats[$key] ?? ''), $matches)) {
                    throw new RuntimeException('Missing or invalid CFBD '.$key.'.');
                }

                return [(int) $matches[1], (int) $matches[2]];
            };
            [$completions, $attempts] = $pair('completionAttempts');
            [$third, $thirdAttempts] = $pair('thirdDownEff');
            [$fourth, $fourthAttempts] = $pair('fourthDownEff');
            [$penalties, $penaltyYards] = $pair('totalPenaltiesYards');
            if ($completions > $attempts || $third > $thirdAttempts || $fourth > $fourthAttempts) {
                throw new RuntimeException('CFBD conversions exceed attempts.');
            }
            $opponent = collect($payload['teams'])->firstWhere('homeAway', $side === 'home' ? 'away' : 'home');
            $opponentSacks = collect($opponent['stats'] ?? [])->firstWhere('category', 'sacks')['stat'] ?? null;
            $rows[$team->id] = ['team_type' => $side, 'total_yards' => $total, 'passing_yards' => $passing,
                'rushing_yards' => $rushing, 'passing_completions' => $completions, 'passing_attempts' => $attempts,
                'rushing_attempts' => $number('rushingAttempts', true), 'first_downs' => $number('firstDowns', true),
                'passing_touchdowns' => $number('passingTDs'), 'rushing_touchdowns' => $number('rushingTDs'),
                'interceptions' => $number('interceptions'), 'fumbles' => $number('totalFumbles'), 'fumbles_lost' => $number('fumblesLost'),
                'third_down_conversions' => $third, 'third_down_attempts' => $thirdAttempts,
                'fourth_down_conversions' => $fourth, 'fourth_down_attempts' => $fourthAttempts,
                'penalties' => $penalties, 'penalty_yards' => $penaltyYards,
                'sacks_allowed' => preg_match('/^\d+$/', (string) $opponentSacks) ? (int) $opponentSacks : null,
                'time_of_possession' => $stats['possessionTime'] ?? null, 'red_zone_attempts' => null, 'red_zone_scores' => null];
        }

        return $rows;
    }
}
