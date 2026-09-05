<?php

namespace App\Services\Sports;

use App\Models\MLB\DepthChartEntry as MlbDepthChartEntry;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\NBA\DepthChartEntry as NbaDepthChartEntry;
use App\Models\NFL\DepthChartEntry as NflDepthChartEntry;
use App\Models\NFL\DepthChartSnapshot as NflDepthChartSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DepthChartImpactService
{
    public function injuryMultiplier(
        string $sport,
        int $teamId,
        int $playerId,
        ?int $season = null,
        CarbonInterface|string|null $asOf = null,
    ): float {
        if ($teamId <= 0 || $playerId <= 0) {
            return 1.0;
        }

        $entry = $this->findEntryForPlayer($sport, $teamId, $playerId, $season, $asOf);
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

    protected function findEntryForPlayer(
        string $sport,
        int $teamId,
        int $playerId,
        ?int $season = null,
        CarbonInterface|string|null $asOf = null,
    ): ?Model {
        $asOfTimestamp = is_string($asOf) ? Carbon::parse($asOf) : $asOf;

        if ($sport === 'nfl' && $asOfTimestamp !== null) {
            $snapshot = NflDepthChartSnapshot::query()
                ->with(['entries' => fn ($query) => $query->where('player_id', $playerId)])
                ->where('team_id', $teamId)
                ->when($season !== null, fn ($query) => $query->where('season', '<=', $season))
                ->where('observed_at', '<=', $asOfTimestamp)
                ->where(function ($query) use ($asOfTimestamp): void {
                    $query->whereNull('source_updated_at')
                        ->orWhere('source_updated_at', '<=', $asOfTimestamp);
                })
                ->latest('observed_at')
                ->latest('id')
                ->first();

            if ($snapshot !== null) {
                return $snapshot->entries
                    ->sortByDesc('is_starter')
                    ->sortBy('depth_rank')
                    ->sortBy('slot_order')
                    ->first();
            }
        }

        $modelClass = $this->depthChartModelClass($sport);
        if ($modelClass === null) {
            return null;
        }

        return $modelClass::query()
            ->where('team_id', $teamId)
            ->where('player_id', $playerId)
            ->when($season !== null, fn ($builder) => $builder->where('season', '<=', $season))
            ->when($sport === 'nfl' && $asOfTimestamp !== null, fn ($builder) => $builder
                ->whereNotNull('source_updated_at')
                ->where('source_updated_at', '<=', $asOfTimestamp))
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
        $roleMultiplier = $this->nflRoleMultiplier($entry, $tokens);
        $depthRank = (int) ($entry->depth_rank ?? 99);
        $isStarter = (bool) ($entry->is_starter ?? false) || $depthRank === 1;

        if ($isStarter && $this->tokensContain($tokens, ['QB'])) {
            return max($baseWeight, $roleMultiplier, $this->configFloat('nfl', 'qb_multiplier', 2.4));
        }

        if (($isStarter || $depthRank <= 2) && $this->tokensContain($tokens, ['RB', 'WR', 'TE'])) {
            return max($baseWeight, $roleMultiplier, $this->configFloat('nfl', 'skill_multiplier', 1.45));
        }

        return max($baseWeight, $roleMultiplier);
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function nflRoleMultiplier(Model $entry, array $tokens): float
    {
        $roles = (array) config('nfl.predictions.depth_chart.role_multipliers', []);
        $depthRank = (int) ($entry->depth_rank ?? 99);

        $candidates = [];
        if ($depthRank === 1 && $this->tokensContain($tokens, ['QB'])) {
            $candidates[] = 'QB';
        }
        if ($depthRank === 1 && $this->tokensContain($tokens, ['LT', 'LEFT TACKLE'])) {
            $candidates[] = 'LT';
        }
        if ($depthRank === 1 && $this->tokensContain($tokens, ['RT', 'RIGHT TACKLE'])) {
            $candidates[] = 'RT';
        }
        if ($depthRank === 1 && $this->tokensContain($tokens, ['C', 'CENTER'])) {
            $candidates[] = 'C';
        }
        if ($this->tokensContain($tokens, ['WR']) && $depthRank === 1) {
            $candidates[] = 'WR1';
        }
        if ($this->tokensContain($tokens, ['WR']) && $depthRank <= 2) {
            $candidates[] = 'WR';
        }
        if ($this->tokensContain($tokens, ['RB']) && $depthRank === 1) {
            $candidates[] = 'RB1';
        }
        if ($this->tokensContain($tokens, ['TE']) && $depthRank === 1) {
            $candidates[] = 'TE1';
        }
        if ($this->tokensContain($tokens, ['EDGE', 'DE', 'OLB']) && $depthRank === 1) {
            $candidates[] = 'EDGE1';
        }
        if ($this->tokensContain($tokens, ['CB']) && $depthRank === 1) {
            $candidates[] = 'CB1';
        }
        if ($this->tokensContain($tokens, ['S', 'FS', 'SS']) && $depthRank === 1) {
            $candidates[] = 'S';
        }
        if ($this->tokensContain($tokens, ['K', 'KICKER']) && $depthRank === 1) {
            $candidates[] = 'K';
        }

        return collect($candidates)
            ->map(fn (string $role): float => is_numeric($roles[$role] ?? null) ? (float) $roles[$role] : 1.0)
            ->max() ?: 1.0;
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
