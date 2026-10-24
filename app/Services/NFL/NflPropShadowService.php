<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class NflPropShadowService
{
    public const POLICY = 'nfl-prop-shadow-v1';

    public function __construct(private NflPropShadowFeatures $features, private NflPropShadowBundle $bundles) {}

    public function run(int $season, ?int $gameId = null): array
    {
        if (! config('nfl.props.shadow.enabled', false)) {
            return ['status' => 'disabled', 'created' => 0];
        }
        $bundle = $this->bundles->load();
        $props = PlayerProp::query()->whereNotNull('player_id')->whereNull('graded_at')
            ->whereIn('market', array_keys($bundle['data']['markets']))
            ->whereHas('game', fn ($query) => $query->where('season', $season)->where('status', 'STATUS_SCHEDULED')
                ->whereIn('season_type', ['2', 'regular'])->whereDate('game_date', '<=', now()->addDays(2)->toDateString()))
            ->when($gameId, fn ($query) => $query->where('game_id', $gameId))
            ->with(['game', 'player'])->get()
            ->filter(fn ($prop) => $this->eligible($prop))
            ->sortBy(fn ($prop) => [$prop->bookmaker === 'draftkings' ? 0 : 1, -$prop->fetched_at->timestamp, $prop->id])
            ->unique(fn ($prop) => $prop->game_id.':'.$prop->player_id.':'.$prop->market)->values();
        $existing = PredictionFeatureSnapshot::query()->where('sport', 'nfl')->where('blend_version', self::POLICY)
            ->where('model_version', 'nfl-prop-shadow-'.substr($bundle['sha256'], 0, 32))
            ->whereIn('game_id', $props->pluck('game_id')->unique())->pluck('snapshot_run_id')->flip();
        $props = $props->reject(fn ($prop) => $existing->has($this->identity($prop, $bundle['sha256'])))->values();
        $samples = $this->features->forProps($props);
        $created = 0;
        foreach ($props as $prop) {
            if (! isset($samples[$prop->id])) {
                continue;
            }
            $sample = $samples[$prop->id];
            $predictions = $this->bundles->predict($bundle['data'], $prop->market, $sample['features']);
            $created += (int) $this->record($prop, $sample, $predictions, $bundle);
        }

        return ['status' => 'completed', 'created' => $created, 'considered' => $props->count(), 'bundle_sha256' => $bundle['sha256']];
    }

    private function record(PlayerProp $prop, array $sample, array $predictions, array $bundle): bool
    {
        return DB::transaction(function () use ($prop, $sample, $predictions, $bundle): bool {
            $current = PlayerProp::query()->with(['game', 'player'])->lockForUpdate()->find($prop->id);
            if ($current) {
                $current->setRelation('game', Game::query()->lockForUpdate()->find($current->game_id));
            }
            if (! $current || ! $this->eligible($current)
                || Arr::only($current->getAttributes(), ['game_id', 'player_id', 'market', 'bookmaker', 'line', 'over_price', 'under_price', 'fetched_at']) !== Arr::only($prop->getAttributes(), ['game_id', 'player_id', 'market', 'bookmaker', 'line', 'over_price', 'under_price', 'fetched_at'])
                || (int) $current->player->team_id !== $sample['team_id']
                || Arr::only($current->player->getAttributes(), ['team_id', 'position', 'full_name']) !== Arr::only($prop->player->getAttributes(), ['team_id', 'position', 'full_name'])
                || $current->game->getAttributes() !== $prop->game->getAttributes()) {
                return false;
            }
            $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($current->game->game_date, $current->game->game_time);
            $outputs = [...$sample['comparators'], ...$predictions];
            $market = ['market' => $prop->market, 'bookmaker' => $prop->bookmaker, 'line' => (float) $prop->line,
                'over_price' => $prop->over_price, 'under_price' => $prop->under_price,
                'quote_observed_at' => $prop->fetched_at->toIso8601String()];
            $snapshot = PredictionFeatureSnapshot::query()->firstOrCreate([
                'snapshot_run_id' => $this->identity($prop, $bundle['sha256']),
            ], [
                'sport' => 'nfl', 'prediction_table' => 'nfl_player_props', 'prediction_id' => $prop->id,
                'game_id' => $prop->game_id, 'model_version' => 'nfl-prop-shadow-'.substr($bundle['sha256'], 0, 32),
                'feature_version' => NflPropShadowFeatures::VERSION, 'blend_version' => self::POLICY,
                'features' => $sample['features'], 'outputs' => $outputs, 'market_context' => $market,
                'model_metadata' => ['bundle_sha256' => $bundle['sha256'], 'dataset_sha256' => $bundle['data']['dataset_sha256'],
                    'frozen_at' => $bundle['data']['frozen_at'], 'player_id' => $sample['player_id'], 'team_id' => $sample['team_id'],
                    'history_stat_ids' => $sample['history_stat_ids'], 'policy' => 'first_eligible_capture_within_24h',
                    'probabilities' => 'not_produced', 'recommendations_approved' => false],
                'feature_hash' => self::fingerprint($sample['features'], $outputs, $market),
                'generated_at' => now(), 'game_start_at' => $kickoff->setTimezone(config('app.timezone')),
                'features_available_at' => $sample['features_observed_at'], 'pregame_safe' => true,
                'availability_status' => 'observed_pregame',
                'source_timestamps' => ['quote_observed_at' => $prop->fetched_at->toIso8601String(),
                    'features_observed_at' => $sample['features_observed_at']],
            ]);

            return $snapshot->wasRecentlyCreated;
        });
    }

    private function identity(PlayerProp $prop, string $bundleHash): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, implode(':', [self::POLICY, $bundleHash, $prop->game_id, $prop->player_id, $prop->market]))->toString();
    }

    private function eligible(PlayerProp $prop): bool
    {
        $game = $prop->game;
        $kickoff = $game ? app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time) : null;

        return $game && $prop->player && $game->status === 'STATUS_SCHEDULED' && $kickoff && $kickoff->isFuture()
            && $kickoff->lte(now()->addDay()) && $prop->graded_at === null && is_numeric($prop->line)
            && $prop->fetched_at && $prop->fetched_at->lte(now()) && $prop->fetched_at->gte($kickoff->subHours(36))
            && (is_numeric($prop->over_price) || is_numeric($prop->under_price));
    }

    public static function fingerprint(array $features, array $outputs, array $market): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive(['features' => $features, 'outputs' => $outputs, 'market' => $market]), JSON_THROW_ON_ERROR));
    }
}
