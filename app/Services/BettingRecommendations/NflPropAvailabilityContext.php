<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class NflPropAvailabilityContext
{
    public const VERSION = 'nfl-availability-v1';

    /**
     * A conservative usage heuristic, not a calibrated injury win probability.
     * Current tables are deliberately never used to reconstruct historical games.
     */
    public function resolve(Model $game, int $playerId, string $market): array
    {
        $context = ['version' => self::VERSION, 'factor' => 1.0, 'unavailable' => false, 'reason' => 'not_pregame', 'absences' => []];
        $kickoff = Carbon::parse($game->game_date)->format('Y-m-d').' '.($game->game_time ?: '00:00:00');
        $kickoff = Carbon::parse($kickoff, 'UTC');
        if ($game->status !== 'STATUS_SCHEDULED' || $kickoff->isPast() || $kickoff->gt(now()->addDays(7))) {
            return $this->finish($context);
        }

        $player = Player::find($playerId);
        if (! $player || ! in_array((int) $player->team_id, [(int) $game->home_team_id, (int) $game->away_team_id], true)) {
            return $this->finish([...$context, 'reason' => 'unmatched_player']);
        }

        // updated_at is the local observation time; source_updated_at may use the provider timezone.
        $injuries = PlayerInjury::query()->where('team_id', $player->team_id)
            ->where('is_active', true)->where('updated_at', '>=', now()->subHours(12))
            ->where('updated_at', '<=', now())->get();
        $ownInjuries = $injuries->where('player_id', $playerId);
        $context['player_statuses'] = $ownInjuries->pluck('status')->sort()->values()->all();
        if ($ownInjuries->contains(fn ($injury) => $this->isOut($injury->status))) {
            return $this->finish([...$context, 'unavailable' => true, 'reason' => 'player_unavailable']);
        }
        if ($ownInjuries->isNotEmpty()) {
            return $this->finish([...$context, 'reason' => 'player_availability_uncertain']);
        }

        $field = match ($market) {
            'player_rush_attempts', 'player_rush_yds' => $player->position === 'RB' ? 'rushing_attempts' : null,
            'player_receptions', 'player_reception_yds' => in_array($player->position, ['RB', 'WR', 'TE'], true) ? 'receiving_targets' : null,
            default => null,
        };
        if ($field === null) {
            return $this->finish([...$context, 'reason' => 'unsupported_usage_market']);
        }

        $depth = DepthChartEntry::query()->where('team_id', $player->team_id)->where('season', $game->season)
            ->where('position_code', $player->position)->where('updated_at', '>=', now()->subDays(8))
            ->where('updated_at', '<=', now())->orderBy('depth_rank')->get()->unique('espn_athlete_id');
        // Resolve transfers by the provider's stable ID without changing the roster.
        // Usage and injuries below still require the current depth-chart team.
        $unlinkedIds = $depth->whereNull('player_id')->pluck('espn_athlete_id')->filter();
        $playersByEspnId = $unlinkedIds->isEmpty() ? collect() : Player::query()
            ->whereIn('espn_id', $unlinkedIds)->get(['id', 'espn_id'])->groupBy('espn_id');
        foreach ($depth->whereNull('player_id') as $entry) {
            $matches = $playersByEspnId->get($entry->espn_athlete_id, collect());
            if ($matches->count() === 1) {
                $entry->player_id = $matches->first()->id;
            }
        }
        if ($depth->isEmpty() || $depth->contains(fn ($entry) => $entry->player_id === null)) {
            return $this->finish([...$context, 'reason' => 'missing_or_unlinked_depth']);
        }
        $context['depth'] = $depth->map(fn ($entry) => ['player_id' => $entry->player_id, 'rank' => $entry->depth_rank])->values()->all();
        $unavailableIds = $injuries->filter(fn ($injury) => $this->isOut($injury->status))->pluck('player_id');
        $available = $depth->whereNotIn('player_id', $unavailableIds);
        // Only the clear first available player at the same position receives reassigned usage.
        if ($available->isEmpty() || (int) $available->first()->player_id !== $playerId
            || $available->where('depth_rank', $available->first()->depth_rank)->count() !== 1) {
            return $this->finish([...$context, 'reason' => 'not_primary_replacement']);
        }
        $baseline = $this->usage($game, $playerId, (int) $player->team_id, $field);
        if ($baseline['games'] < 3 || $baseline['average'] <= 0) {
            return $this->finish([...$context, 'reason' => 'insufficient_usage_history']);
        }

        $vacated = 0.0;
        foreach ($injuries->whereIn('player_id', $depth->pluck('player_id'))->filter(fn ($injury) => $this->isOut($injury->status))->unique('player_id') as $injury) {
            if (! $injury->injury_date
                || $injury->injury_date->lt(now()->subDays(14)->startOfDay()) || $injury->injury_date->gt(now())) {
                continue;
            }
            // If the beneficiary has already played since this injury, historical usage may include the benefit.
            if ($baseline['latest_game'] >= $injury->injury_date->toDateString()) {
                continue;
            }
            $usage = $this->usage($game, (int) $injury->player_id, (int) $player->team_id, $field);
            if ($usage['games'] < 3 || $usage['average'] <= 0) {
                continue;
            }
            $vacated += $usage['average'];
            $context['absences'][] = ['player_id' => $injury->player_id, 'status' => $injury->status,
                'injury_date' => $injury->injury_date->toDateString(), 'average_usage' => round($usage['average'], 3)];
        }
        $context['baseline_usage'] = round($baseline['average'], 3);
        $context['usage_field'] = $field;
        // Share only half the vacated opportunities, with a hard 15% projection cap.
        $context['factor'] = round(1 + min(0.15, 0.5 * $vacated / $baseline['average']), 3);
        $context['reason'] = $vacated > 0 ? 'confirmed_teammate_absence' : 'no_new_confirmed_absence';

        return $this->finish($context);
    }

    private function isOut(?string $status): bool
    {
        return in_array(strtolower(trim($status ?? '')), ['out', 'injured reserve', 'ir', 'suspended', 'reserve/pup', 'pup'], true);
    }

    private function usage(Model $game, int $playerId, int $teamId, string $field): array
    {
        $stats = PlayerStat::query()->join('nfl_games as g', 'g.id', '=', 'nfl_player_stats.game_id')
            ->where('nfl_player_stats.player_id', $playerId)->where('nfl_player_stats.team_id', $teamId)
            ->where('g.status', 'STATUS_FINAL')->whereIn('g.season_type', [2, 3])
            ->where('g.season', '>=', (int) $game->season - 1)
            ->where('g.game_date', '<', Carbon::parse($game->game_date)->toDateString())
            ->where('g.game_date', '<', now()->utc()->toDateString())
            ->whereNotNull('nfl_player_stats.'.$field)->orderByDesc('g.game_date')->limit(8)
            ->get(['nfl_player_stats.'.$field, 'g.game_date']);

        return ['games' => $stats->count(), 'average' => (float) $stats->avg($field), 'latest_game' => $stats->first()?->game_date];
    }

    private function finish(array $context): array
    {
        $context['fingerprint'] = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));

        return $context;
    }
}
