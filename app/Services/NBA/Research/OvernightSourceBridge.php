<?php

namespace App\Services\NBA\Research;

use App\Actions\ESPN\NBA\SyncPlayerInjuries;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjuryObservation;
use App\Models\NBA\Team;
use App\Models\ResearchSource;
use App\Services\ESPN\NBA\EspnService;
use App\Services\NBA\Injuries\BoundedInjuryFeedClient;
use App\Services\NBA\Injuries\InjuryCanaryScope;
use App\Services\NBA\Injuries\InjuryFeedClient;
use App\Services\NBA\Injuries\InjurySnapshotRecorder;
use App\Services\NBA\Injuries\StructuredInjuryEvidence;
use App\Services\Research\GameResearchService;
use App\Services\Research\ResearchDocumentStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Source acquisition and descriptive assessment only. Never dispatches analysis or invokes AI. */
class OvernightSourceBridge
{
    public function __construct(private readonly InjuryCanaryScope $scope, private readonly GameResearchService $research) {}

    public function plan(): array
    {
        if (! config('nba.source_bridge.enabled', false)) {
            return ['status' => 'disabled', 'reason' => 'bridge_disabled'];
        }
        if (! config('nba.injuries.observations_enabled') || ! config('nba.injuries.research_adapter_enabled') || ! $this->research->enabled('nba')) {
            return ['status' => 'failed', 'reason' => 'source_capability_disabled'];
        }
        $clock = now('America/Chicago');
        if ($clock->hour >= 8 && $clock->hour < 23) {
            return ['status' => 'skipped', 'reason' => 'outside_overnight_window'];
        }
        if (! in_array($clock->month, (array) config('healthchecks.season_months.nba'), true)) {
            return ['status' => 'skipped', 'reason' => 'outside_nba_season'];
        }
        $ids = config('nba.injuries.canary_team_ids', []);
        if (! is_array($ids) || $ids === [] || count($ids) > 2 || count(array_unique($ids)) !== count($ids)
            || ! collect($ids)->every(fn ($id) => (is_int($id) || is_string($id)) && ctype_digit((string) $id) && $this->scope->allowsTeam((int) $id))) {
            return ['status' => 'failed', 'reason' => 'scope_denied'];
        }
        $teams = Team::query()->whereIn('id', $ids)->orderBy('id')->get();
        if ($teams->count() !== count($ids) || ! $teams->every(fn ($team) => $this->teamSourceApproved($team))) {
            return ['status' => 'failed', 'reason' => 'team_source_not_approved'];
        }
        // The canonical event clock, not legacy UTC date/time columns, determines admission.
        $games = Game::query()->with('sportEvent')->whereIn('home_team_id', $ids)->whereIn('away_team_id', $ids)
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->whereHas('sportEvent', fn ($event) => $event->where('sport', 'nba')->where('starts_at', '>', now())
                ->where('starts_at', '<=', now()->addHours(8)))->limit(2)->get();
        if ($games->count() > 1) {
            return ['status' => 'failed', 'reason' => 'game_limit_exceeded'];
        }
        foreach ($games as $game) {
            $source = ResearchSource::where('sport', 'nba')->where('source_key', CaptureGameContext::sourceKey($game))->first();
            if (! $this->scope->allowsGame($game) || $game->home_team_id === $game->away_team_id
                || (int) $game->sportEvent->season !== (int) $game->season
                || (string) $game->sportEvent->season_type !== (string) $game->season_type
                || ! ctype_digit((string) $game->espn_event_id) || ! $source || $source->kind !== 'structured_espn'
                || $source->team_id !== null || $source->url !== CaptureGameContext::summaryUrl($game)
                || ! app(ResearchDocumentStore::class)->allowed($source, $source->url)) {
                return ['status' => 'failed', 'reason' => 'context_source_or_event_not_approved'];
            }
        }

        return ['status' => 'ready', 'reason' => null, 'team_ids' => $teams->modelKeys(), 'game_ids' => $games->modelKeys(),
            'scope_expires_at' => config('nba.injuries.canary_expires_at'), 'horizon_hours' => 8, 'maximum_fetches' => 24];
    }

    public function run(bool $dryRun = false): array
    {
        $plan = $this->plan();
        if ($dryRun || $plan['status'] !== 'ready') {
            return [...$plan, 'dry_run' => $dryRun];
        }
        $result = Cache::lock('nba:overnight-source-bridge', 660)->get(function (): array {
            // Recheck after admission/locking. One marker per physical UTC half-hour handles DST safely.
            $plan = $this->plan();
            if ($plan['status'] !== 'ready') {
                return $plan;
            }
            $slot = gmdate('YmdHi', intdiv(now('UTC')->timestamp, 1800) * 1800);
            $key = 'nba:overnight-source-bridge:completed:'.$slot.':'.hash('sha256', json_encode([$plan['team_ids'], $plan['game_ids'], $plan['scope_expires_at'], GameContextEvidence::VERSION], JSON_THROW_ON_ERROR));
            if ($this->completedSlotIsCurrent(Cache::get($key), $plan)) {
                return ['status' => 'skipped', 'reason' => 'slot_already_completed', 'slot_utc' => $slot];
            }
            $previous = app(InjuryFeedClient::class);
            $bounded = new BoundedInjuryFeedClient(24);
            app()->instance(InjuryFeedClient::class, $bounded);
            try {
                $result = $this->acquire($plan, $slot, $key, $bounded);

                return [...$result, 'fetches_used' => $bounded->used, 'finished_at' => now()->toIso8601String()];
            } finally {
                app()->instance(InjuryFeedClient::class, $previous);
            }
        });

        return $result === false ? ['status' => 'skipped', 'reason' => 'bridge_busy'] : $result;
    }

    private function acquire(array $plan, string $slot, string $key, BoundedInjuryFeedClient $client): array
    {
        $result = [...$plan, 'status' => 'failed', 'reason' => null, 'started_at' => now()->toIso8601String(),
            'teams' => [], 'games' => [], 'slot_utc' => $slot];
        try {
            foreach ($plan['team_ids'] as $id) {
                $team = Team::findOrFail($id);
                if (! $this->scope->allowsTeam($id) || ! $this->teamSourceApproved($team)) {
                    throw new \RuntimeException('admission_changed');
                }
                $client->authorizeWith($this->teamSource($team));
                (new SyncPlayerInjuries(new EspnService))->execute((string) $team->espn_id, requireCapture: true);
                $head = $this->verifiedHead($team);
                $result['teams'][] = $head;
                if (! $head['verified']) {
                    $result['reason'] = 'team_observation_unverified';

                    return $result;
                }
            }
            foreach ($plan['game_ids'] as $id) {
                $game = Game::with('sportEvent')->findOrFail($id);
                $client->authorizeWith(ResearchSource::where('sport', 'nba')->where('source_key', CaptureGameContext::sourceKey($game))->firstOrFail(), $game);
                $packet = app(CaptureGameContext::class)->capture($game);
                $errors = collect($packet->responses)->map(fn ($response) => $response['error_code'])->filter()->all();
                if ($errors !== []) {
                    $result['games'][] = ['game_id' => $id, 'snapshot_id' => $packet->id, 'errors' => $errors];
                    $result['reason'] = 'context_acquisition_incomplete';

                    return $result;
                }
                // No network under these locks. Match the existing source-only command's lock order.
                $evidence = DB::transaction(function () use ($game): array {
                    Team::whereIn('id', [$game->home_team_id, $game->away_team_id])->orderBy('id')->lockForUpdate()->get();
                    if (! $this->scope->allowsGame($game->fresh())) {
                        throw new \RuntimeException('admission_changed');
                    }

                    return $this->research->assess($game->fresh(), 'nba');
                });
                $current = $this->research->forGame($game->fresh(), 'nba');
                $coverage = $current['source_coverage'] ?? [];
                $verified = ($current['applicability'] ?? null) === 'current' && isset($evidence['report_id'])
                    && $evidence['report_id'] === ($current['report_id'] ?? null);
                foreach (['availability', 'lineup', 'rotation', 'rest_travel'] as $topic) {
                    $verified = $verified && ($coverage[$topic]['home'] ?? false) && ($coverage[$topic]['away'] ?? false);
                }
                $result['games'][] = ['game_id' => $id, 'snapshot_id' => $packet->id, 'report_id' => $evidence['report_id'] ?? null,
                    'status' => $current['status'], 'applicability' => $current['applicability'], 'coverage' => $coverage,
                    'expires_at' => $current['expires_at'], 'verified' => $verified];
                if (! $verified) {
                    $result['reason'] = 'assessment_coverage_incomplete';

                    return $result;
                }
            }
            $result['teams'] = array_map(fn ($id) => $this->verifiedHead(Team::findOrFail($id)), $plan['team_ids']);
            if ($this->plan()['status'] !== 'ready' || ! collect($result['teams'])->every(fn ($head) => $head['verified'])) {
                throw new \RuntimeException('admission_or_head_changed');
            }
            foreach ($plan['game_ids'] as $id) {
                if (($this->research->forGame(Game::findOrFail($id), 'nba')['applicability'] ?? null) !== 'current') {
                    throw new \RuntimeException('evidence_changed_before_completion');
                }
            }
            $result['status'] = 'success';
            $result['reason'] = $plan['game_ids'] === [] ? 'team_health_only_no_upcoming_game' : 'sources_acquired_and_assessed';
            Cache::put($key, ['receipts' => array_column($result['teams'], 'receipt_id', 'team_id'),
                'reports' => array_column($result['games'], 'report_id', 'game_id')], now()->addMinutes(35));

            return $result;
        } catch (Throwable $error) {
            return [...$result, 'status' => 'failed', 'reason' => 'source_refresh_failed', 'error_class' => class_basename($error)];
        }
    }

    private function completedSlotIsCurrent(mixed $marker, array $plan): bool
    {
        if (! is_array($marker) || ! isset($marker['receipts'], $marker['reports'])) {
            return false;
        }
        foreach ($plan['team_ids'] as $id) {
            $head = $this->verifiedHead(Team::findOrFail($id));
            if (! $head['verified'] || $head['receipt_id'] !== ($marker['receipts'][$id] ?? null)) {
                return false;
            }
        }
        foreach ($plan['game_ids'] as $id) {
            $evidence = $this->research->forGame(Game::findOrFail($id), 'nba');
            if (($evidence['applicability'] ?? null) !== 'current' || ($evidence['report_id'] ?? null) !== ($marker['reports'][$id] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function teamSource(Team $team): ?ResearchSource
    {
        return ResearchSource::where('sport', 'nba')->where('team_id', $team->id)
            ->where('source_key', StructuredInjuryEvidence::sourceKey((string) $team->espn_id))->first();
    }

    private function teamSourceApproved(Team $team): bool
    {
        if (! ctype_digit((string) $team->espn_id)) {
            return false;
        }
        $source = $this->teamSource($team);

        return $source && $source->kind === 'structured_espn'
            && app(ResearchDocumentStore::class)->allowed($source, app(InjuryFeedClient::class)->teamUrl((string) $team->espn_id));
    }

    private function verifiedHead(Team $team): array
    {
        $state = DB::table('nba_injury_team_states')->where('team_id', $team->id)->first();
        $receipt = $state?->latest_observation_id ? PlayerInjuryObservation::with('snapshot.entries')->find($state->latest_observation_id) : null;
        $source = $this->teamSource($team);
        $mapping = Player::where('team_id', $team->id)->pluck('espn_id', 'id')->all();
        $verified = $this->scope->allowsTeam($team->id) && $this->teamSourceApproved($team) && $receipt
            && (int) $state->issued_sequence === $receipt->acquisition_sequence
            && $receipt->team_id === $team->id && $receipt->projection_applied && in_array($receipt->outcome, ['complete', 'empty'], true) && $receipt->issues === []
            && $receipt->snapshot && $receipt->snapshot->team_id === $team->id && $receipt->snapshot->provider === 'espn'
            && $source && app(ResearchDocumentStore::class)->allowed($source, $receipt->snapshot->source_url)
            && $receipt->snapshot->normalizer_version === InjurySnapshotRecorder::VERSION
            && $receipt->snapshot->entry_count === $receipt->snapshot->entries->count()
            && in_array($receipt->snapshot->outcome, ['complete', 'empty'], true)
            && $receipt->snapshot->entries->every(fn ($entry) => $entry->issues === [] && isset($mapping[$entry->player_id])
                && (string) $mapping[$entry->player_id] === (string) $entry->espn_athlete_id)
            && $receipt->observed_at && ! $receipt->observed_at->isFuture() && $receipt->observed_at->gte(now()->subMinutes(120));

        return ['team_id' => $team->id, 'receipt_id' => $receipt?->id, 'snapshot_id' => $receipt?->snapshot_id,
            'outcome' => $receipt?->outcome, 'observed_at' => $receipt?->observed_at?->toIso8601String(),
            'cache_hit' => $receipt?->cache_hit, 'verified' => (bool) $verified];
    }
}
