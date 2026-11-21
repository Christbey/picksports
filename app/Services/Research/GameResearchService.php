<?php

namespace App\Services\Research;

use App\Models\GameResearchAssessment;
use App\Models\SportsGameContextReport;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GameResearchService
{
    public function __construct(
        private readonly ResearchCapabilityRegistry $capabilities,
        private readonly StoredGameEvidence $evidence,
        private readonly NflResearchCompatibility $nfl,
        private readonly SportsDateWindowService $dates,
    ) {}

    public function enabled(string $sport): bool
    {
        return $this->capabilities->enabled(strtolower(trim($sport)));
    }

    /** Strictly read-only: no source fetches, queue dispatch, provider calls or writes. @return array<string, mixed> */
    public function forGame(Model $game, string $sport): array
    {
        $sport = $this->capabilities->validate($game, $sport);
        if ($sport === 'nfl') {
            return $this->nfl->read($game) ?? $this->unavailable($game, $sport, 'no_stored_research');
        }
        if (! $this->enabled($sport)) {
            return $this->unavailable($game, $sport, 'research_disabled');
        }
        if (! $this->installed()) {
            return $this->unavailable($game, $sport, 'research_storage_unavailable');
        }
        $row = GameResearchAssessment::query()->where('sport', $sport)->where('game_id', $game->id)->latest('id')->first();
        if ($row === null) {
            return $this->unavailable($game, $sport, 'no_stored_research');
        }
        $result = $row->assessment;
        $startsAt = $game->sportEvent?->starts_at ?? $this->dates->gameDateTimeUtc($game->game_date, $game->game_time);
        $result['applicability'] = match (true) {
            $startsAt?->lte(now()) || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true) => 'archived',
            $row->expires_at === null || $row->expires_at->lte(now()) => 'expired',
            $row->evidence_hash !== $this->evidence->build($game, $sport)['evidence_hash'] => 'outdated',
            default => 'current',
        };

        return $result;
    }

    /** Explicit background/write entry point. Force never enables sources or paid discovery. @return array<string, mixed> */
    public function assess(Model $game, string $sport, bool $force = false): array
    {
        $sport = $this->capabilities->validate($game, $sport);
        if (! $this->enabled($sport)) {
            return $this->unavailable($game, $sport, 'research_disabled');
        }
        $startsAt = $game->sportEvent?->starts_at ?? $this->dates->gameDateTimeUtc($game->game_date, $game->game_time);
        if ($startsAt === null || ! $startsAt->isFuture() || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            return $this->unavailable($game, $sport, 'pregame_research_ineligible');
        }
        if ($sport === 'nfl') {
            $this->nfl->assess($game);

            return $this->forGame($game, $sport);
        }
        if (! $this->installed()) {
            return $this->unavailable($game, $sport, 'research_storage_unavailable');
        }
        $result = Cache::lock("shared-research:{$sport}:{$game->id}", 60)->get(function () use ($game, $sport, $startsAt): array {
            $evidence = $this->evidence->build($game, $sport);
            $previous = GameResearchAssessment::query()->where('sport', $sport)->where('game_id', $game->id)->latest('id')->first();
            if ($previous && $previous->evidence_hash === $evidence['evidence_hash']) {
                return [...$previous->assessment, 'applicability' => $previous->expires_at?->isFuture() ? 'current' : 'expired'];
            }
            $expiry = now()->addMinutes($this->capabilities->freshnessMinutes($sport))->min($startsAt);
            foreach ($evidence['sources'] as $source) {
                foreach (['observed_at', 'published_at'] as $timestamp) {
                    if ($source[$timestamp] !== null) {
                        $expiry = $expiry->min(CarbonImmutable::parse($source[$timestamp])->addMinutes($this->capabilities->freshnessMinutes($sport)));
                    }
                }
            }
            $factsAvailable = $evidence['sources'] !== [];
            $balanced = $evidence['supporting'] !== [] && $evidence['concerns'] !== [];
            $result = [
                ...$evidence, 'schema_version' => 'game_research_summary_v1',
                'status' => ! $factsAvailable ? 'unavailable' : ($evidence['unresolved'] === [] && $balanced ? 'ready' : 'partial'),
                'applicability' => 'current', 'assessed_at' => now()->toIso8601String(), 'expires_at' => $expiry->toIso8601String(),
                'reason_codes' => [...($evidence['unresolved'] === [] ? [] : ['source_coverage_incomplete']), ...($balanced ? [] : ['two_sided_evidence_incomplete'])],
                'report_id' => null,
            ];

            return DB::transaction(function () use ($game, $sport, $result, $expiry, $previous): array {
                $report = SportsGameContextReport::query()->create([
                    'sport' => $sport, 'game_id' => $game->id, 'game_date' => $game->game_date,
                    'status' => $result['status'], 'provider' => 'stored', 'model' => null,
                    'prompt_version' => 'stored-research-v1', 'input_hash' => $result['evidence_hash'],
                    'confidence' => 0, 'summary' => 'Descriptive stored evidence; no research-driven numerical adjustment or betting approval.',
                    'facts' => [...$result['supporting'], ...$result['concerns'], ...$result['facts']], 'sources' => $result['sources'],
                    'risk_flags' => $result['reason_codes'], 'raw_payload' => $result,
                    'researched_at' => now(), 'expires_at' => $expiry,
                ]);
                $result['report_id'] = $report->id;
                GameResearchAssessment::query()->create([
                    'sport' => $sport, 'game_id' => $game->id, 'sport_event_id' => $game->sport_event_id,
                    'report_id' => $report->id, 'evidence_hash' => $result['evidence_hash'], 'policy_version' => 'stored-research-v1',
                    'assessment_hash' => hash('sha256', $result['evidence_hash'].'|after|'.($previous?->id ?? 0)),
                    'assessment' => $result, 'assessed_at' => now(), 'expires_at' => $expiry,
                ]);

                return $result;
            });
        });

        return is_array($result) ? $result : $this->unavailable($game, $sport, 'research_already_running');
    }

    private function installed(): bool
    {
        return Schema::hasTable('game_research_assessments') && Schema::hasTable('research_documents')
            && Schema::hasTable('sports_game_context_reports');
    }

    /** @return array<string, mixed> */
    private function unavailable(Model $game, string $sport, string $reason): array
    {
        return [
            'schema_version' => 'game_research_summary_v1', 'sport' => $sport, 'game_id' => $game->id, 'sport_event_id' => $game->sport_event_id,
            'status' => 'unavailable', 'applicability' => 'current', 'assessed_at' => null, 'expires_at' => null,
            'supporting' => [], 'concerns' => [], 'unresolved' => [], 'sources' => [], 'source_coverage' => [],
            'report_id' => null, 'evidence_hash' => null, 'reason_codes' => [$reason],
        ];
    }
}
