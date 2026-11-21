<?php

namespace App\Services\Research;

use App\Models\ResearchDocument;
use App\Services\NBA\Injuries\StructuredInjuryEvidence;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Database\Eloquent\Model;

/** Descriptive adapters share storage semantics; topics and coverage remain sport-specific. */
class StoredGameEvidence
{
    public function __construct(
        private readonly ResearchCapabilityRegistry $capabilities,
        private readonly SportsDateWindowService $dates,
        private readonly CanonicalPayloadHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(Model $game, string $sport): array
    {
        $cutoff = $game->sportEvent?->starts_at ?? $this->dates->gameDateTimeUtc($game->game_date, $game->game_time);
        $teams = ['home' => (int) $game->home_team_id, 'away' => (int) $game->away_team_id];
        $topics = $this->capabilities->topics($sport);
        $freshAfter = now()->subMinutes($this->capabilities->freshnessMinutes($sport));
        $documents = ResearchDocument::query()->with('source')
            ->where('sport', $sport)->where('observed_at', '<=', now())
            ->where('observed_at', '>=', $freshAfter)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '>=', $freshAfter))
            ->when($cutoff, fn ($query) => $query->where('observed_at', '<', $cutoff)
                ->where(fn ($published) => $published->whereNull('published_at')->orWhere('published_at', '<', $cutoff)))
            ->where(fn ($query) => $query->where(fn ($team) => $team->whereNull('sport_event_id')->whereIn('team_id', array_values($teams)))
                ->when($game->sport_event_id !== null, fn ($event) => $event->orWhere('sport_event_id', $game->sport_event_id)))
            ->orderByDesc('observed_at')->orderByDesc('id')
            ->limit(max(1, (int) config('research.maximum_documents_per_game', 24)) * 4)->get()
            ->unique(fn (ResearchDocument $document): string => $document->source_id.'|'.$document->url_hash.'|'.$document->scope_hash)
            ->take(max(1, (int) config('research.maximum_documents_per_game', 24)))->values();
        $findings = $concerns = $neutral = $sources = [];
        $coverage = array_fill_keys($topics, ['home' => false, 'away' => false]);
        foreach ($documents as $document) {
            if (! $document->source || ! app(ResearchDocumentStore::class)->allowed($document->source, $document->url)) {
                continue;
            }
            foreach ($document->claims as $claim) {
                if (! in_array($claim['topic'] ?? null, $topics, true)
                    || ! is_string($claim['text'] ?? null) || ! str_contains($document->body, $claim['text'])) {
                    continue;
                }
                $side = array_search((int) $document->team_id, $teams, true);
                if ($side === false) {
                    // Event-only context is descriptive and never claims team coverage.
                    $side = 'game';
                } else {
                    $coverage[$claim['topic']][$side] = true;
                }
                $finding = [
                    'claim' => $claim['text'], 'interpretation' => 'Stored source excerpt; no numerical adjustment is applied.',
                    'topic' => $claim['topic'], 'team_side' => $side, 'source_url' => $document->url,
                    'evidence_kind' => 'document', 'evidence_reference' => 'research_documents:'.$document->id,
                    'evidence_status' => 'reference_verified', 'observed_at' => $document->observed_at->toIso8601String(),
                    'published_at' => $document->published_at?->toIso8601String(),
                ];
                match ($claim['stance'] ?? 'neutral') {
                    'supporting' => $findings[] = $finding,
                    'concern' => $concerns[] = $finding,
                    default => $neutral[] = $finding,
                };
                $sources[$document->id] = [
                    'document_id' => $document->id, 'url' => $document->url, 'content_hash' => $document->content_hash,
                    'observed_at' => $document->observed_at->toIso8601String(), 'published_at' => $document->published_at?->toIso8601String(),
                ];
            }
        }
        $structuredUnresolved = [];
        if ($sport === 'nba' && config('nba.injuries.research_adapter_enabled', false)) {
            $structured = app(StructuredInjuryEvidence::class)->build($game, $cutoff);
            foreach (['home', 'away'] as $side) {
                if ($structured['coverage'][$side] && $coverage['availability'][$side]) {
                    $structuredUnresolved[] = ['claim' => 'Manual and structured availability sources require reconciliation for the '.$side.' team.',
                        'topic' => 'availability', 'team_side' => $side, 'reason_code' => 'source_reconciliation_required',
                        'blocking' => false, 'scope' => 'research', 'evidence_status' => 'unresolved'];
                }
                $coverage['availability'][$side] = $coverage['availability'][$side] || $structured['coverage'][$side];
            }
            $neutral = [...$neutral, ...$structured['facts']];
            $sources = [...array_values($sources), ...$structured['sources']];
            $structuredUnresolved = [...$structuredUnresolved, ...$structured['unresolved']];
        }
        $unresolved = $structuredUnresolved;
        foreach ($coverage as $topic => $sides) {
            foreach ($sides as $side => $available) {
                if (! $available) {
                    $unresolved[] = [
                        'claim' => ucfirst(str_replace('_', ' ', $topic)).' evidence is unavailable for the '.$side.' team.',
                        'topic' => $topic, 'team_side' => $side, 'reason_code' => 'source_coverage_missing',
                        'blocking' => false, 'scope' => 'research', 'evidence_status' => 'unavailable',
                    ];
                }
            }
        }
        $performance = $this->performance($game, $teams, $cutoff);
        $body = [
            'schema_version' => 'stored_game_evidence_v1', 'sport' => $sport, 'game_id' => (int) $game->id,
            'sport_event_id' => $game->sport_event_id !== null ? (int) $game->sport_event_id : null,
            'event' => [(int) $game->season, (string) $game->season_type, (int) $game->home_team_id, (int) $game->away_team_id, $cutoff?->copy()->utc()->toIso8601String()],
            'supporting' => $findings, 'concerns' => $concerns, 'facts' => $neutral, 'unresolved' => $unresolved,
            'sources' => array_values($sources), 'source_coverage' => $coverage, 'season_evidence' => $performance,
            'numeric_adjustment_policy' => 'descriptive_only',
        ];
        $body['evidence_hash'] = $this->hasher->hash($body);

        return $body;
    }

    /** @param array<string, int> $teams @return array<string, mixed> */
    private function performance(Model $game, array $teams, mixed $cutoff): array
    {
        if ($cutoff === null) {
            return [];
        }
        $rows = $game->newQuery()->where('season', $game->season)->where('season_type', $game->season_type)
            ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->where('id', '!=', $game->id)->whereDate('game_date', '<=', $cutoff->toDateString())
            ->where(fn ($query) => $query->whereIn('home_team_id', array_values($teams))->orWhereIn('away_team_id', array_values($teams)))
            ->orderByDesc('game_date')->orderByDesc('id')->limit(400)->get()
            ->filter(function (Model $row) use ($cutoff): bool {
                $start = $this->dates->gameDateTimeUtc($row->game_date, $row->game_time);

                return $start !== null && $start->lt($cutoff) && $start->lt(now());
            });
        $result = [];
        foreach ($teams as $side => $teamId) {
            $completed = $rows->filter(fn ($row) => in_array($teamId, [(int) $row->home_team_id, (int) $row->away_team_id], true));
            $margins = $completed->map(fn ($row) => (int) $row->home_team_id === $teamId
                ? (float) $row->home_score - (float) $row->away_score
                : (float) $row->away_score - (float) $row->home_score);
            $result[$side] = [
                'sample_games' => $completed->count(), 'wins' => $margins->filter(fn ($margin) => $margin > 0)->count(),
                'losses' => $margins->filter(fn ($margin) => $margin < 0)->count(),
                'ties' => $margins->filter(fn ($margin) => $margin == 0)->count(),
                'average_margin' => $margins->isNotEmpty() ? round($margins->average(), 3) : null,
                'record_references' => $completed->map(fn ($row) => $row->getTable().':'.$row->id)->values()->all(),
                'source' => 'stored_completed_results', 'coverage_limit' => 400,
            ];
        }

        return $result;
    }
}
