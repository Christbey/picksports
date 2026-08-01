<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\GameContextSignal;
use App\Models\CFB\PreseasonTeamSignal;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\GameOddsSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeriveGameContextSignalsCommand extends Command
{
    protected $signature = 'cfb:derive-game-context-signals
        {--season= : Season to derive, defaults to configured CFB season}
        {--week= : Optional week filter}
        {--game-id= : Optional single game id}
        {--dry-run : Summarize without writing}
        {--json : Emit machine-readable summary}';

    protected $description = 'Derive CFB game-level context signals for availability, weather, ratings, market movement, schedule, scheme, and special teams';

    /**
     * @var array<int, TeamMetric|null>
     */
    private array $metricCache = [];

    /**
     * @var array<int, object|null>
     */
    private array $preseasonCache = [];

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: config('cfb.season.default'));
        $week = $this->option('week') === null ? null : (int) $this->option('week');
        $gameId = $this->option('game-id') === null ? null : (int) $this->option('game-id');
        $dryRun = (bool) $this->option('dry-run');

        $query = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season);

        if ($week !== null) {
            $query->where('week', $week);
        }

        if ($gameId !== null) {
            $query->whereKey($gameId);
        }

        $games = $query->orderBy('game_date')->get();
        $rows = $games->map(fn (Game $game): array => $this->contextRow($game))->values();

        if (! $dryRun) {
            foreach ($rows as $row) {
                GameContextSignal::query()->updateOrCreate(
                    ['game_id' => $row['game_id']],
                    $row
                );
            }
        }

        $summary = [
            'season' => $season,
            'week' => $week,
            'game_id' => $gameId,
            'games' => $rows->count(),
            'written' => $dryRun ? 0 : $rows->count(),
            'dry_run' => $dryRun,
            'coverage' => [
                'player_availability' => $rows->filter(fn (array $row): bool => $row['player_availability_payload']['available'] ?? false)->count(),
                'weather_venue' => $rows->filter(fn (array $row): bool => $row['weather_payload']['available'] ?? false)->count(),
                'rating_consensus' => $rows->filter(fn (array $row): bool => $row['rating_consensus_payload']['available'] ?? false)->count(),
                'explosiveness_success' => $rows->filter(fn (array $row): bool => $row['explosiveness_payload']['available'] ?? false)->count(),
                'line_qb_environment' => $rows->filter(fn (array $row): bool => $row['line_qb_payload']['available'] ?? false)->count(),
                'market_movement' => $rows->filter(fn (array $row): bool => $row['market_movement_payload']['available'] ?? false)->count(),
                'schedule_context' => $rows->filter(fn (array $row): bool => $row['schedule_context_payload']['available'] ?? false)->count(),
                'coach_scheme' => $rows->filter(fn (array $row): bool => $row['scheme_payload']['available'] ?? false)->count(),
                'special_teams' => $rows->filter(fn (array $row): bool => $row['special_teams_payload']['available'] ?? false)->count(),
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Derived CFB context signals for %d game(s)%s.',
            $rows->count(),
            $dryRun ? ' (dry run)' : ''
        ));
        $this->table(
            ['Family', 'Games with data'],
            collect($summary['coverage'])
                ->map(fn (int $count, string $family): array => [$family, (string) $count])
                ->values()
                ->all()
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextRow(Game $game): array
    {
        $homeMetrics = $this->metricsFor((int) $game->home_team_id, (int) $game->season);
        $awayMetrics = $this->metricsFor((int) $game->away_team_id, (int) $game->season);
        $homePreseason = $this->preseasonFor((int) $game->home_team_id, (int) $game->season);
        $awayPreseason = $this->preseasonFor((int) $game->away_team_id, (int) $game->season);

        $availability = $this->availabilitySignal($game);
        $weather = $this->weatherSignal($game);
        $ratings = $this->ratingConsensusSignal($homeMetrics, $awayMetrics);
        $explosiveness = $this->explosivenessSignal($homeMetrics, $awayMetrics);
        $lineQb = $this->lineQbSignal($homePreseason, $awayPreseason);
        $market = $this->marketSignal($game);
        $schedule = $this->scheduleSignal($game);
        $scheme = $this->schemeSignal($homePreseason, $awayPreseason);
        $specialTeams = $this->specialTeamsSignal($game);

        return [
            'game_id' => (int) $game->id,
            'home_team_id' => (int) $game->home_team_id,
            'away_team_id' => (int) $game->away_team_id,
            'season' => (int) $game->season,
            'week' => $game->week === null ? null : (int) $game->week,

            'player_availability_spread_adjustment' => $availability['spread_adjustment'],
            'player_availability_total_adjustment' => $availability['total_adjustment'],
            'home_player_availability_score' => $availability['home_score'],
            'away_player_availability_score' => $availability['away_score'],
            'home_qb_availability_score' => $availability['home_qb_score'],
            'away_qb_availability_score' => $availability['away_qb_score'],
            'player_availability_payload' => $availability,

            'weather_spread_adjustment' => $weather['spread_adjustment'],
            'weather_total_adjustment' => $weather['total_adjustment'],
            'temperature_f' => $weather['temperature_f'],
            'wind_speed_mph' => $weather['wind_speed_mph'],
            'wind_gust_mph' => $weather['wind_gust_mph'],
            'precipitation_inches' => $weather['precipitation_inches'],
            'weather_condition' => $weather['condition'],
            'weather_payload' => $weather,

            'rating_consensus_spread_adjustment' => $ratings['spread_adjustment'],
            'home_rating_consensus' => $ratings['home_score'],
            'away_rating_consensus' => $ratings['away_score'],
            'rating_consensus_payload' => $ratings,

            'explosiveness_spread_adjustment' => $explosiveness['spread_adjustment'],
            'explosiveness_total_adjustment' => $explosiveness['total_adjustment'],
            'home_explosiveness_score' => $explosiveness['home_score'],
            'away_explosiveness_score' => $explosiveness['away_score'],
            'explosiveness_payload' => $explosiveness,

            'line_qb_spread_adjustment' => $lineQb['spread_adjustment'],
            'home_line_qb_score' => $lineQb['home_score'],
            'away_line_qb_score' => $lineQb['away_score'],
            'line_qb_payload' => $lineQb,

            'market_movement_spread_adjustment' => $market['spread_adjustment'],
            'market_confidence_penalty' => $market['confidence_penalty'],
            'opening_home_spread' => $market['opening_home_spread'],
            'current_home_spread' => $market['current_home_spread'],
            'closing_home_spread' => $market['closing_home_spread'],
            'consensus_home_spread' => $market['consensus_home_spread'],
            'market_movement_payload' => $market,

            'schedule_context_spread_adjustment' => $schedule['spread_adjustment'],
            'schedule_context_total_adjustment' => $schedule['total_adjustment'],
            'schedule_confidence_penalty' => $schedule['confidence_penalty'],
            'home_rest_days' => $schedule['home_rest_days'],
            'away_rest_days' => $schedule['away_rest_days'],
            'schedule_context_payload' => $schedule,

            'scheme_spread_adjustment' => $scheme['spread_adjustment'],
            'scheme_total_adjustment' => $scheme['total_adjustment'],
            'scheme_confidence_penalty' => $scheme['confidence_penalty'],
            'home_scheme_change_score' => $scheme['home_score'],
            'away_scheme_change_score' => $scheme['away_score'],
            'scheme_payload' => $scheme,

            'special_teams_spread_adjustment' => $specialTeams['spread_adjustment'],
            'special_teams_total_adjustment' => $specialTeams['total_adjustment'],
            'home_special_teams_score' => $specialTeams['home_score'],
            'away_special_teams_score' => $specialTeams['away_score'],
            'special_teams_payload' => $specialTeams,

            'signal_payload' => [
                'version' => 'cfb-game-context-v1',
                'families' => [
                    'player_availability',
                    'weather_venue',
                    'rating_consensus',
                    'explosiveness_success',
                    'line_qb_environment',
                    'market_movement',
                    'schedule_context',
                    'coach_scheme',
                    'special_teams',
                ],
            ],
            'synced_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function availabilitySignal(Game $game): array
    {
        if (! Schema::hasTable('cfb_player_injuries')) {
            return $this->emptySignal('missing_cfb_player_injuries_table') + [
                'home_qb_score' => null,
                'away_qb_score' => null,
            ];
        }

        $home = $this->injuryImpactForTeam((int) $game->home_team_id, $game);
        $away = $this->injuryImpactForTeam((int) $game->away_team_id, $game);
        $spreadAdjustment = $this->clamp(($away['weighted'] - $home['weighted']) * 0.45, 2.5);
        $totalAdjustment = -$this->clamp(($home['weighted'] + $away['weighted']) * 0.18, 2.5);

        return [
            'available' => $home['count'] > 0 || $away['count'] > 0,
            'source' => 'cfb_player_injuries',
            'spread_adjustment' => round($spreadAdjustment, 3),
            'total_adjustment' => round($totalAdjustment, 3),
            'home_score' => round($home['weighted'], 3),
            'away_score' => round($away['weighted'], 3),
            'home_qb_score' => round($home['qb_weighted'], 3),
            'away_qb_score' => round($away['qb_weighted'], 3),
            'home_count' => $home['count'],
            'away_count' => $away['count'],
        ];
    }

    /**
     * @return array{weighted:float,qb_weighted:float,count:int}
     */
    private function injuryImpactForTeam(int $teamId, Game $game): array
    {
        $query = DB::table('cfb_player_injuries as injuries')
            ->leftJoin('cfb_players as players', 'players.id', '=', 'injuries.player_id')
            ->where('injuries.team_id', $teamId)
            ->where('injuries.is_active', true);

        if ($game->game_date !== null) {
            $query->where(function ($query) use ($game): void {
                $query->whereNull('injuries.injury_date')
                    ->orWhereDate('injuries.injury_date', '<=', $game->game_date);
            })->where(function ($query) use ($game): void {
                $query->whereNull('injuries.return_date')
                    ->orWhereDate('injuries.return_date', '>=', $game->game_date);
            });
        }

        $weighted = 0.0;
        $qbWeighted = 0.0;
        $count = 0;

        foreach ($query->get(['injuries.status', 'players.position']) as $row) {
            $statusWeight = $this->injuryStatusWeight((string) ($row->status ?? ''));
            if ($statusWeight <= 0.0) {
                continue;
            }

            $position = strtoupper((string) ($row->position ?? ''));
            $impact = $statusWeight * $this->positionWeight($position);
            $weighted += $impact;
            $qbWeighted += $position === 'QB' ? $impact : 0.0;
            $count++;
        }

        return [
            'weighted' => $weighted,
            'qb_weighted' => $qbWeighted,
            'count' => $count,
        ];
    }

    private function injuryStatusWeight(string $status): float
    {
        $normalized = strtolower($status);

        return match (true) {
            str_contains($normalized, 'out') => 1.0,
            str_contains($normalized, 'doubtful') => 0.75,
            str_contains($normalized, 'questionable') => 0.35,
            str_contains($normalized, 'probable') => 0.10,
            default => 0.0,
        };
    }

    private function positionWeight(string $position): float
    {
        return match ($position) {
            'QB' => 2.75,
            'OL', 'C', 'G', 'OG', 'OT', 'T' => 1.35,
            'DL', 'DT', 'DE', 'EDGE', 'CB' => 1.30,
            'WR', 'RB', 'TE', 'LB', 'S' => 1.15,
            'K', 'P' => 0.65,
            default => 1.0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function weatherSignal(Game $game): array
    {
        if (! Schema::hasTable('cfb_game_weather')) {
            return $this->emptySignal('missing_cfb_game_weather_table') + [
                'temperature_f' => null,
                'wind_speed_mph' => null,
                'wind_gust_mph' => null,
                'precipitation_inches' => null,
                'condition' => null,
            ];
        }

        $weather = DB::table('cfb_game_weather')->where('game_id', $game->id)->first();
        if (! $weather) {
            return $this->emptySignal('missing_weather_row') + [
                'temperature_f' => null,
                'wind_speed_mph' => null,
                'wind_gust_mph' => null,
                'precipitation_inches' => null,
                'condition' => null,
            ];
        }

        $wind = $this->number($weather->wind_speed_mph ?? null) ?? 0.0;
        $gust = $this->number($weather->wind_gust_mph ?? null) ?? 0.0;
        $precip = $this->number($weather->precipitation_inches ?? null) ?? 0.0;
        $temp = $this->number($weather->temperature_f ?? null);
        $totalAdjustment = 0.0;

        if ($wind >= 15.0 || $gust >= 25.0) {
            $totalAdjustment -= 1.0;
        }

        if ($precip >= 0.03) {
            $totalAdjustment -= 0.6;
        }

        if ($temp !== null && ($temp <= 35.0 || $temp >= 92.0)) {
            $totalAdjustment -= 0.3;
        }

        return [
            'available' => true,
            'source' => 'cfb_game_weather',
            'spread_adjustment' => 0.0,
            'total_adjustment' => round($this->clamp($totalAdjustment, 2.5), 3),
            'temperature_f' => $temp,
            'wind_speed_mph' => $wind,
            'wind_gust_mph' => $gust,
            'precipitation_inches' => $precip,
            'condition' => $weather->condition_code ?? $weather->weather_condition ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ratingConsensusSignal(?TeamMetric $homeMetrics, ?TeamMetric $awayMetrics): array
    {
        $homeScore = $this->ratingConsensusScore($homeMetrics);
        $awayScore = $this->ratingConsensusScore($awayMetrics);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptySignal('missing_rating_consensus_inputs');
        }

        return [
            'available' => true,
            'source' => 'cfb_team_metrics',
            'spread_adjustment' => round($this->clamp(($homeScore - $awayScore) * 0.18, 1.25), 3),
            'total_adjustment' => 0.0,
            'home_score' => round($homeScore, 3),
            'away_score' => round($awayScore, 3),
        ];
    }

    private function ratingConsensusScore(?TeamMetric $metrics): ?float
    {
        if (! $metrics) {
            return null;
        }

        $cfbdWepaNet = $this->number($metrics->cfbd_wepa_net);
        $values = array_filter([
            $this->number($metrics->fpi),
            $this->number($metrics->power_rating),
            $this->number($metrics->net_rating),
            $cfbdWepaNet === null ? null : $cfbdWepaNet * 8.0,
        ], fn (?float $value): bool => $value !== null);

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @return array<string, mixed>
     */
    private function explosivenessSignal(?TeamMetric $homeMetrics, ?TeamMetric $awayMetrics): array
    {
        $homeScore = $this->explosivenessScore($homeMetrics);
        $awayScore = $this->explosivenessScore($awayMetrics);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptySignal('missing_explosiveness_inputs');
        }

        return [
            'available' => true,
            'source' => 'cfb_team_metrics_true_epa_wepa',
            'spread_adjustment' => round($this->clamp(($homeScore - $awayScore) * 0.75, 1.75), 3),
            'total_adjustment' => round($this->clamp(($homeScore + $awayScore) * 0.35, 2.5), 3),
            'home_score' => round($homeScore, 3),
            'away_score' => round($awayScore, 3),
        ];
    }

    private function explosivenessScore(?TeamMetric $metrics): ?float
    {
        if (! $metrics) {
            return null;
        }

        $offense = $this->number($metrics->offensive_true_epa_per_play)
            ?? $this->number($metrics->cfbd_wepa_offense);
        $defense = $this->number($metrics->defensive_true_epa_per_play)
            ?? $this->number($metrics->cfbd_wepa_defense);

        if ($offense === null && $defense === null) {
            return null;
        }

        return ($offense ?? 0.0) - (($defense ?? 0.0) * 0.55);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineQbSignal(?object $home, ?object $away): array
    {
        $homeScore = $this->lineQbScore($home);
        $awayScore = $this->lineQbScore($away);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptySignal('missing_line_qb_inputs');
        }

        return [
            'available' => true,
            'source' => 'cfb_preseason_team_signals',
            'spread_adjustment' => round($this->clamp(($homeScore - $awayScore) * 1.0, 1.75), 3),
            'total_adjustment' => 0.0,
            'home_score' => round($homeScore, 3),
            'away_score' => round($awayScore, 3),
        ];
    }

    private function lineQbScore(?object $signal): ?float
    {
        if (! $signal) {
            return null;
        }

        $parts = array_filter([
            $this->number($signal->transfer_qb_net_value) === null ? null : $this->clamp(((float) $signal->transfer_qb_net_value) / 4.0, 1.0),
            $this->number($signal->transfer_ol_net_value) === null ? null : $this->clamp(((float) $signal->transfer_ol_net_value) / 4.0, 1.0),
            $this->number($signal->returning_percent_passing_ppa),
            $this->qbContinuityClassScore((string) $signal->qb_continuity_classification),
        ], fn (?float $value): bool => $value !== null);

        return $parts === [] ? null : array_sum($parts) / count($parts);
    }

    private function qbContinuityClassScore(string $classification): ?float
    {
        return match ($classification) {
            'returning_starter' => 1.0,
            'experienced_transfer' => 0.35,
            'injury_return' => 0.15,
            'new_transfer' => -0.35,
            'first_time_starter' => -0.75,
            'unsettled' => -0.80,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function marketSignal(Game $game): array
    {
        $snapshotLines = $this->snapshotHomeLines($game);
        $currentHomeSpread = $this->homeSpreadFromOddsData($game->odds_data, $game->homeTeam)
            ?? ($snapshotLines->last()['home_spread'] ?? null);

        if ($currentHomeSpread === null && $snapshotLines->isEmpty()) {
            return $this->emptySignal('missing_market_lines') + [
                'opening_home_spread' => null,
                'current_home_spread' => null,
                'closing_home_spread' => null,
                'consensus_home_spread' => null,
            ];
        }

        $opening = $snapshotLines->first()['home_spread'] ?? $currentHomeSpread;
        $closing = $snapshotLines->last()['home_spread'] ?? null;
        $consensus = $snapshotLines->isEmpty()
            ? $currentHomeSpread
            : round((float) $snapshotLines->avg('home_spread'), 2);
        $moveTowardHome = $currentHomeSpread !== null && $opening !== null
            ? (float) $opening - (float) $currentHomeSpread
            : 0.0;

        return [
            'available' => true,
            'source' => $snapshotLines->isEmpty() ? 'cfb_games.odds_data' : 'game_odds_snapshots',
            'spread_adjustment' => round($this->clamp($moveTowardHome * 0.08, 0.75), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'opening_home_spread' => $this->roundOrNull($opening, 2),
            'current_home_spread' => $this->roundOrNull($currentHomeSpread, 2),
            'closing_home_spread' => $this->roundOrNull($closing, 2),
            'consensus_home_spread' => $this->roundOrNull($consensus, 2),
            'snapshot_count' => $snapshotLines->count(),
        ];
    }

    /**
     * @return Collection<int, array{home_spread:float,captured_at:string|null}>
     */
    private function snapshotHomeLines(Game $game): Collection
    {
        if (! Schema::hasTable('game_odds_snapshots')) {
            return collect();
        }

        return GameOddsSnapshot::query()
            ->where('sport', 'cfb')
            ->where('game_table', 'cfb_games')
            ->where('game_id', $game->id)
            ->orderBy('captured_at')
            ->get()
            ->map(function (GameOddsSnapshot $snapshot) use ($game): ?array {
                $homeSpread = $this->homeSpreadFromOddsData($snapshot->odds_data, $game->homeTeam)
                    ?? $this->number(data_get($snapshot->market_context, 'bookmaker_home_spread'));

                if ($homeSpread === null) {
                    return null;
                }

                return [
                    'home_spread' => $homeSpread,
                    'captured_at' => $snapshot->captured_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleSignal(Game $game): array
    {
        $homeRest = $this->restDays((int) $game->home_team_id, $game);
        $awayRest = $this->restDays((int) $game->away_team_id, $game);
        $spreadAdjustment = 0.0;

        if ($homeRest !== null && $awayRest !== null) {
            $spreadAdjustment = $this->clamp(($homeRest - $awayRest) * 0.18, 1.25);
        }

        return [
            'available' => $homeRest !== null || $awayRest !== null || $game->neutral_site !== null || $game->conference_game !== null,
            'source' => 'cfb_games_schedule',
            'spread_adjustment' => round($spreadAdjustment, 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'home_rest_days' => $homeRest,
            'away_rest_days' => $awayRest,
            'neutral_site' => (bool) ($game->neutral_site ?? false),
            'conference_game' => (bool) ($game->conference_game ?? false),
        ];
    }

    private function restDays(int $teamId, Game $game): ?int
    {
        if ($game->game_date === null) {
            return null;
        }

        $previousDate = Game::query()
            ->where('season', $game->season)
            ->where('status', config('cfb.statuses.final', 'STATUS_FINAL'))
            ->where('game_date', '<', $game->game_date)
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->value('game_date');

        if ($previousDate === null) {
            return null;
        }

        return max(0, Carbon::parse($previousDate)->startOfDay()->diffInDays($game->game_date->copy()->startOfDay()));
    }

    /**
     * @return array<string, mixed>
     */
    private function schemeSignal(?object $home, ?object $away): array
    {
        $homeScore = $this->schemeScore($home);
        $awayScore = $this->schemeScore($away);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptySignal('missing_scheme_inputs');
        }

        $uncertainSides = (int) ($homeScore < 0.5) + (int) ($awayScore < 0.5);

        return [
            'available' => true,
            'source' => 'cfb_preseason_team_signals',
            'spread_adjustment' => round($this->clamp(($homeScore - $awayScore) * 0.65, 1.0), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => round($uncertainSides * 0.75, 3),
            'home_score' => round($homeScore, 3),
            'away_score' => round($awayScore, 3),
        ];
    }

    private function schemeScore(?object $signal): ?float
    {
        if (! $signal) {
            return null;
        }

        if ($signal->coordinator_continuity_score !== null) {
            return (float) $signal->coordinator_continuity_score;
        }

        $hasAny = $signal->new_head_coach !== null
            || $signal->new_offensive_coordinator !== null
            || $signal->new_defensive_coordinator !== null;

        if (! $hasAny) {
            return null;
        }

        $score = 1.0;
        $score -= $signal->new_head_coach ? 1.0 : 0.0;
        $score -= $signal->new_offensive_coordinator ? 0.35 : 0.0;
        $score -= $signal->new_defensive_coordinator ? 0.35 : 0.0;

        return max(-1.0, min(1.0, $score));
    }

    /**
     * @return array<string, mixed>
     */
    private function specialTeamsSignal(Game $game): array
    {
        $homeScore = $this->latestSpecialTeamsScore((int) $game->home_team_id, (int) $game->season, (int) ($game->week ?? 0));
        $awayScore = $this->latestSpecialTeamsScore((int) $game->away_team_id, (int) $game->season, (int) ($game->week ?? 0));

        if ($homeScore === null || $awayScore === null) {
            return $this->emptySignal('missing_special_teams_inputs');
        }

        return [
            'available' => true,
            'source' => 'cfb_fpi_ratings.special_teams',
            'spread_adjustment' => round($this->clamp(($homeScore - $awayScore) * 0.08, 0.9), 3),
            'total_adjustment' => 0.0,
            'home_score' => round($homeScore, 3),
            'away_score' => round($awayScore, 3),
        ];
    }

    private function latestSpecialTeamsScore(int $teamId, int $season, int $week): ?float
    {
        if (! Schema::hasTable('cfb_fpi_ratings')) {
            return null;
        }

        $query = DB::table('cfb_fpi_ratings')
            ->where('team_id', $teamId)
            ->where('season', $season);

        if ($week > 0 && Schema::hasColumn('cfb_fpi_ratings', 'week')) {
            $query->where('week', '<=', $week)->orderByDesc('week');
        }

        $columns = collect(['special_teams', 'special_teams_fpi'])
            ->filter(fn (string $column): bool => Schema::hasColumn('cfb_fpi_ratings', $column))
            ->values()
            ->all();

        if ($columns === []) {
            return null;
        }

        $row = $query->latest('id')->first($columns);
        if (! $row) {
            return null;
        }

        return $this->number($row->special_teams ?? null)
            ?? $this->number($row->special_teams_fpi ?? null);
    }

    private function metricsFor(int $teamId, int $season): ?TeamMetric
    {
        $key = ($season * 100000) + $teamId;

        return $this->metricCache[$key] ??= TeamMetric::query()
            ->where('team_id', $teamId)
            ->where('season', $season)
            ->latest('calculation_date')
            ->latest('id')
            ->first();
    }

    private function preseasonFor(int $teamId, int $season): ?object
    {
        $key = ($season * 100000) + $teamId;

        if (array_key_exists($key, $this->preseasonCache)) {
            return $this->preseasonCache[$key];
        }

        $rows = PreseasonTeamSignal::query()
            ->where('team_id', $teamId)
            ->where('season', '<=', $season)
            ->orderByDesc('season')
            ->latest('id')
            ->limit((int) config('cfb.predictions.preseason.prior_season_fallback_limit', 5))
            ->get();

        if ($rows->isEmpty()) {
            return $this->preseasonCache[$key] = null;
        }

        $signals = [];
        $sourceSeasons = [];

        foreach ($rows as $row) {
            $rowSignals = $row->toArray();
            $rowSeason = is_numeric($rowSignals['season'] ?? null) ? (int) $rowSignals['season'] : null;

            foreach ($rowSignals as $column => $value) {
                if (! is_string($column) || $column === '') {
                    continue;
                }

                if ($this->hasSignalValue($signals[$column] ?? null) || ! $this->hasSignalValue($value)) {
                    continue;
                }

                $signals[$column] = $value;

                if ($rowSeason !== null && ! str_starts_with($column, '_')) {
                    $sourceSeasons[$column] = $rowSeason;
                }
            }
        }

        if ($signals === []) {
            return $this->preseasonCache[$key] = null;
        }

        $signals['_source_seasons'] = $sourceSeasons;

        return $this->preseasonCache[$key] = (object) $signals;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySignal(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'spread_adjustment' => 0.0,
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'home_score' => null,
            'away_score' => null,
        ];
    }

    private function homeSpreadFromOddsData(mixed $oddsData, ?Team $homeTeam): ?float
    {
        if (! is_array($oddsData) || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        $homeNames = $this->teamNames($homeTeam);
        $spreads = [];

        foreach ($oddsData['bookmakers'] as $bookmaker) {
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
                    if (! is_numeric($outcome['point'] ?? null)) {
                        continue;
                    }

                    if ($this->outcomeMatchesTeam((string) ($outcome['name'] ?? ''), $homeNames)) {
                        $spreads[] = (float) $outcome['point'];
                    }
                }
            }
        }

        return $spreads === [] ? null : round(array_sum($spreads) / count($spreads), 2);
    }

    /**
     * @return list<string>
     */
    private function teamNames(?Team $team): array
    {
        if (! $team) {
            return [];
        }

        return collect([
            $team->display_name,
            $team->short_display_name,
            $team->name,
            $team->school,
            $team->location,
            $team->abbreviation,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->normalizeName($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $teamNames
     */
    private function outcomeMatchesTeam(string $outcomeName, array $teamNames): bool
    {
        $normalized = $this->normalizeName($outcomeName);

        if ($normalized === '') {
            return false;
        }

        foreach ($teamNames as $teamName) {
            if ($normalized === $teamName || str_contains($normalized, $teamName) || str_contains($teamName, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeName(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?: '';
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function hasSignalValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized !== '' && strtolower($normalized) !== 'unknown';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function roundOrNull(mixed $value, int $precision = 3): ?float
    {
        return is_numeric($value) ? round((float) $value, $precision) : null;
    }

    private function clamp(float $value, float $maxAbs): float
    {
        return max(-$maxAbs, min($maxAbs, $value));
    }
}
