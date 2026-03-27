<?php

namespace App\Services\Sports;

use App\Models\MLB\DepthChartEntry as MlbDepthChartEntry;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\NBA\DepthChartEntry as NbaDepthChartEntry;
use App\Models\NFL\DepthChartEntry as NflDepthChartEntry;
use Illuminate\Database\Eloquent\Model;

class DepthChartImpactService
{
    public function injuryMultiplier(string $sport, int $teamId, int $playerId, ?int $season = null): float
    {
        if ($teamId <= 0 || $playerId <= 0) {
            return 1.0;
        }

        $entry = $this->findEntryForPlayer($sport, $teamId, $playerId, $season);
        if (! $entry) {
            return 1.0;
        }

        return round($this->weightForEntry($sport, $entry), 2);
    }

    public function mlbLikelyStarterPitcherId(int $teamId, ?int $season = null): ?int
    {
        $query = MlbDepthChartEntry::query()
            ->where('team_id', $teamId)
            ->when($season !== null, fn ($builder) => $builder->where('season', '<=', $season))
            ->orderByDesc('season')
            ->orderByDesc('is_starter')
            ->orderBy('depth_rank')
            ->orderBy('slot_order');

        /** @var MlbDepthChartEntry|null $entry */
        $entry = $query->get()->first(fn (MlbDepthChartEntry $candidate) => $this->isPitcherEntry($candidate));

        if (! $entry) {
            return null;
        }

        if ($entry->player_id) {
            return (int) $entry->player_id;
        }

        $espnAthleteId = trim((string) $entry->espn_athlete_id);
        if ($espnAthleteId === '') {
            return null;
        }

        return MlbPlayer::query()
            ->where('team_id', $teamId)
            ->where('espn_id', $espnAthleteId)
            ->value('id');
    }

    protected function findEntryForPlayer(string $sport, int $teamId, int $playerId, ?int $season = null): ?Model
    {
        $modelClass = $this->depthChartModelClass($sport);
        if ($modelClass === null) {
            return null;
        }

        return $modelClass::query()
            ->where('team_id', $teamId)
            ->where('player_id', $playerId)
            ->when($season !== null, fn ($builder) => $builder->where('season', '<=', $season))
            ->orderByDesc('season')
            ->orderByDesc('is_starter')
            ->orderBy('depth_rank')
            ->orderBy('slot_order')
            ->first();
    }

    protected function weightForEntry(string $sport, Model $entry): float
    {
        $starterWeight = $this->configFloat($sport, 'starter_multiplier', 1.35);
        $rotationWeight = $this->configFloat($sport, 'rotation_multiplier', 1.10);

        $baseWeight = $entry->is_starter
            ? $starterWeight
            : ((int) ($entry->depth_rank ?? 99) <= 2 ? $rotationWeight : 1.0);

        $positionWeight = match ($sport) {
            'nfl' => $this->nflPositionWeight($entry, $baseWeight),
            'mlb' => $this->mlbPositionWeight($entry, $baseWeight),
            default => $baseWeight,
        };

        return max(1.0, $positionWeight);
    }

    protected function nflPositionWeight(Model $entry, float $baseWeight): float
    {
        $tokens = $this->entryTokens($entry);

        if ($this->tokensContain($tokens, ['QB'])) {
            return max($baseWeight, $this->configFloat('nfl', 'qb_multiplier', 2.4));
        }

        if ($this->tokensContain($tokens, ['RB', 'WR', 'TE'])) {
            return max($baseWeight, $this->configFloat('nfl', 'skill_multiplier', 1.45));
        }

        return $baseWeight;
    }

    protected function mlbPositionWeight(Model $entry, float $baseWeight): float
    {
        if ($this->isPitcherEntry($entry)) {
            return max($baseWeight, $this->configFloat('mlb', 'pitcher_multiplier', 1.6));
        }

        return $baseWeight;
    }

    protected function isPitcherEntry(Model $entry): bool
    {
        return $this->tokensContain($this->entryTokens($entry), ['SP', 'P', 'PITCHER', 'STARTING PITCHER']);
    }

    /**
     * @return list<string>
     */
    protected function entryTokens(Model $entry): array
    {
        $values = [
            $entry->position_slot_key ?? null,
            $entry->position_code ?? null,
            $entry->position_name ?? null,
            $entry->position_display_name ?? null,
        ];

        $tokens = [];

        foreach ($values as $value) {
            $normalized = strtoupper(trim((string) $value));
            if ($normalized === '') {
                continue;
            }

            $tokens[] = $normalized;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $needles
     */
    protected function tokensContain(array $tokens, array $needles): bool
    {
        foreach ($tokens as $token) {
            foreach ($needles as $needle) {
                if ($token === $needle || str_contains($token, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function configFloat(string $sport, string $key, float $default): float
    {
        $singular = config("{$sport}.prediction.depth_chart.{$key}");
        if (is_numeric($singular)) {
            return (float) $singular;
        }

        $plural = config("{$sport}.predictions.depth_chart.{$key}");
        if (is_numeric($plural)) {
            return (float) $plural;
        }

        return $default;
    }

    /**
     * @return class-string<Model>|null
     */
    protected function depthChartModelClass(string $sport): ?string
    {
        return match ($sport) {
            'nfl' => NflDepthChartEntry::class,
            'nba' => NbaDepthChartEntry::class,
            'mlb' => MlbDepthChartEntry::class,
            default => null,
        };
    }
}
