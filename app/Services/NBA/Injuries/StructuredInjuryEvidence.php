<?php

namespace App\Services\NBA\Injuries;

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjuryObservation;
use App\Models\NBA\Team;
use App\Models\ResearchSource;
use App\Services\Research\ResearchDocumentStore;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read stored NBA JSON only. No network, documents, source activation or paid work. */
class StructuredInjuryEvidence
{
    public const VERSION = 'espn-nba-availability-v1';

    public static function sourceKey(string $espnId): string
    {
        return 'espn-nba-injuries:'.$espnId;
    }

    public function build(Game $game, ?CarbonInterface $cutoff): array
    {
        $result = ['facts' => [], 'sources' => [], 'unresolved' => [], 'coverage' => ['home' => false, 'away' => false]];
        foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $side => $teamId) {
            $reason = 'structured_observation_missing';
            $team = Team::query()->find($teamId);
            $source = $team ? ResearchSource::query()->where('sport', 'nba')->where('team_id', $teamId)
                ->where('source_key', self::sourceKey((string) $team->espn_id))->first() : null;
            $state = Schema::hasTable('nba_injury_team_states')
                ? DB::table('nba_injury_team_states')->where('team_id', $teamId)->first() : null;
            $receipt = $state?->latest_observation_id
                ? PlayerInjuryObservation::query()->with('snapshot.entries')->find($state->latest_observation_id) : null;
            $mapping = $receipt ? Player::query()->where('team_id', $teamId)->pluck('espn_id', 'id')->all() : [];
            $url = $team ? app(InjuryFeedClient::class)->teamUrl((string) $team->espn_id) : '';
            if (! app(InjuryCanaryScope::class)->allowsGame($game)) {
                $reason = 'structured_canary_scope_denied';
            } elseif (! config('nba.injuries.observations_enabled', false)) {
                $reason = 'structured_capture_disabled';
            } elseif (! $game->sport_event_id || $game->sportEvent?->sport !== 'nba'
                || (int) $game->sportEvent->season !== (int) $game->season
                || (string) $game->sportEvent->season_type !== (string) $game->season_type
                || $game->home_team_id === $game->away_team_id) {
                $reason = 'structured_event_identity_invalid';
            } elseif (! $source || $source->kind !== 'structured_espn' || ! app(ResearchDocumentStore::class)->allowed($source, $url)) {
                $reason = 'structured_source_not_approved';
            } elseif ($state && (! $receipt || (int) $state->issued_sequence !== $receipt->acquisition_sequence)) {
                $reason = 'structured_acquisition_pending';
            } elseif ($receipt && ! in_array($receipt->outcome, ['complete', 'empty'], true)) {
                $reason = 'structured_observation_'.$receipt->outcome;
            } elseif ($receipt && (! $receipt->projection_applied || $receipt->team_id !== $team->id
                || $receipt->snapshot?->team_id !== $team->id || $receipt->snapshot->provider !== 'espn'
                || $receipt->snapshot->normalizer_version !== InjurySnapshotRecorder::VERSION
                || $receipt->snapshot->entry_count !== $receipt->snapshot->entries->count()
                || ! in_array($receipt->snapshot->outcome, ['complete', 'empty'], true) || $receipt->issues !== [])) {
                $reason = 'structured_observation_unverified';
            } elseif ($receipt && $receipt->snapshot->entries->contains(fn ($entry) => $entry->issues !== []
                || ! isset($mapping[$entry->player_id]) || (string) $mapping[$entry->player_id] !== (string) $entry->espn_athlete_id)) {
                $reason = 'structured_roster_mapping_changed';
            } elseif ($receipt && (! $receipt->observed_at || $receipt->observed_at->isFuture()
                || $receipt->observed_at->lt(now()->subMinutes((int) config('research.sports.nba.freshness_minutes', 120)))
                || $cutoff === null || $receipt->observed_at->gte($cutoff))) {
                $reason = 'structured_observation_stale_or_out_of_scope';
            } elseif ($receipt && app(ResearchDocumentStore::class)->allowed($source, $receipt->snapshot->source_url)) {
                $snapshot = $receipt->snapshot;
                $provenance = ['provider' => 'espn', 'publisher' => 'ESPN', 'source_label' => 'ESPN-reported NBA availability',
                    'source_scope' => 'team_collection', 'parser_version' => self::VERSION, 'snapshot_id' => $snapshot->id,
                    'observation_id' => $receipt->id, 'payload_hash' => $snapshot->payload_hash, 'material_hash' => $snapshot->material_hash,
                    'observed_at' => $receipt->observed_at->toIso8601String(), 'published_at' => null];
                $base = [...$provenance, 'topic' => 'availability', 'team_side' => $side,
                    'evidence_kind' => 'structured', 'evidence_status' => 'reference_verified', 'source_url' => $snapshot->source_url,
                    'interpretation' => 'Normalized ESPN fields, not a source quotation or official game designation. No numerical adjustment is applied.'];
                if ($snapshot->entry_count === 0) {
                    $result['facts'][] = [...$base, 'claim' => 'ESPN reports an empty team injury collection; this does not confirm every player is available.',
                        'evidence_reference' => 'nba_player_injury_snapshots:'.$snapshot->id, 'json_pointer' => '/0',
                        'normalized_status' => 'no_injuries_reported'];
                }
                foreach ($snapshot->entries as $entry) {
                    $raw = $entry->status;
                    $meaning = match (strtolower(trim((string) $raw))) {
                        'out' => 'reported_out', 'doubtful' => 'reported_doubtful', 'questionable' => 'reported_questionable',
                        'probable' => 'reported_probable', 'active', 'available' => 'reported_available',
                        default => 'ambiguous',
                    };
                    $result['facts'][] = [...$base, 'claim' => 'ESPN athlete '.$entry->espn_athlete_id.': reported status '.$raw.'.',
                        'evidence_reference' => 'nba_player_injury_snapshot_entries:'.$entry->id, 'json_pointer' => '/status',
                        'espn_athlete_id' => $entry->espn_athlete_id, 'raw_status' => $raw, 'normalized_status' => $meaning,
                        'source_time_field' => $entry->source_time_field, 'source_time_raw' => $entry->source_time_raw];
                }
                $result['coverage'][$side] = true;
                $result['sources'][] = [...$provenance, 'url' => $snapshot->source_url, 'title' => 'ESPN-reported NBA availability',
                    'source_id' => $source->id, 'content_hash' => $snapshot->payload_hash];

                continue;
            }
            $result['unresolved'][] = ['claim' => 'Verified structured availability is unavailable for the '.$side.' team.',
                'topic' => 'availability', 'team_side' => $side, 'reason_code' => $reason, 'blocking' => false,
                'scope' => 'research', 'evidence_status' => 'unavailable'];
        }

        return $result;
    }
}
