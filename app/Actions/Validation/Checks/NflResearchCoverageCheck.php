<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\NFL\Predictions\NflPregameHorizon;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NflResearchCoverageCheck implements ValidationCheck
{
    public function run(string $sport, array $profile): ?array
    {
        if ($sport !== 'nfl' || ! Schema::hasTable('nfl_games')) {
            return null;
        }

        $days = max(1, min(31, (int) ($profile['window_days'] ?? 7)));
        $now = now()->toImmutable();
        $end = $now->addDays($days);
        $dates = app(SportsDateWindowService::class);
        $games = DB::table('nfl_games')
            ->whereIn('season_type', NflPregameHorizon::seasonTypes())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->where('game_date', '>=', $now->utc()->toDateString())
            ->where('game_date', '<', $end->utc()->addDay()->toDateString())
            ->get(['id', 'game_date', 'game_time'])
            ->filter(function (object $game) use ($dates, $now, $end): bool {
                $kickoff = $dates->gameDateTimeUtc($game->game_date, $game->game_time);

                return $kickoff !== null && $kickoff->gt($now) && $kickoff->lte($end);
            });
        $ids = $games->pluck('id')->all();
        $reports = collect();
        $revisions = collect();
        if ($ids !== [] && Schema::hasTable('nfl_research_revisions')) {
            $latest = DB::table('nfl_research_revisions')->whereIn('game_id', $ids)->selectRaw('MAX(id)')->groupBy('game_id');
            $revisions = DB::table('nfl_research_revisions')->whereIn('id', $latest)
                ->get(['id', 'game_id', 'report_id', 'brief'])->keyBy('game_id');
        }
        if ($ids !== [] && Schema::hasTable('sports_game_context_reports')) {
            $latest = DB::table('sports_game_context_reports')->where('sport', 'nfl')->whereIn('game_id', $ids)
                ->selectRaw('MAX(id)')->groupBy('game_id');
            $reports = DB::table('sports_game_context_reports')->where('sport', 'nfl')
                ->where(fn ($query) => $query->whereIn('id', $latest)->orWhereIn('id', $revisions->pluck('report_id')->filter()->all()))
                ->get(['id', 'game_id', 'status', 'sources', 'researched_at', 'expires_at'])->keyBy('id');
        }
        $latestReports = $reports->sortByDesc('id')->unique('game_id')->keyBy('game_id');

        $enabled = (bool) config('nfl_research.enabled', true)
            && (bool) config('ai.features.nfl_game_context_research.enabled', true);
        $missing = $stale = $partial = $unlinked = $uncited = $held = $blocking = [];
        $covered = 0;
        foreach ($games as $game) {
            $id = (int) $game->id;
            $report = $latestReports->get($id);
            $revision = $revisions->get($id);
            $hardGap = false;
            if (! $report) {
                $missing[] = $id;
                $hardGap = true;
            } else {
                if (! $report->researched_at || ! $report->expires_at
                    || $now->parse($report->researched_at)->gt($now)
                    || $now->parse($report->expires_at)->lte($now)) {
                    $stale[] = $id;
                    $hardGap = true;
                }
                if ($report->status !== 'ready') {
                    $partial[] = $id;
                }
                $cited = $this->hasCitations($report);
                if (! $cited) {
                    $uncited[] = $id;
                    $hardGap = $hardGap || $report->status === 'ready';
                }
                // Semantic no-op research can intentionally reuse an immutable
                // revision. Its original evidence must still be fresh and cited.
                $linked = $reports->get($revision->report_id ?? null);
                if (! $linked || (int) $linked->game_id !== $id
                    || ! $linked->researched_at || ! $linked->expires_at
                    || $now->parse($linked->researched_at)->gt($now)
                    || $now->parse($linked->expires_at)->lte($now)
                    || ($linked->status === 'ready' && ! $this->hasCitations($linked))) {
                    $unlinked[] = $id;
                    $hardGap = true;
                }
                if ($linked && $linked->status !== 'ready' && ! in_array($id, $partial, true)) {
                    $partial[] = $id;
                }
                if (! $hardGap && $report->status === 'ready' && $linked?->status === 'ready' && $cited) {
                    $covered++;
                }
            }

            $eligibility = data_get(json_decode($revision->brief ?? '{}', true), 'eligibility', []);
            if (($eligibility['status'] ?? null) === 'hold'
                || ($eligibility['data_complete'] ?? true) === false
                || ! empty($eligibility['data_reasons'])) {
                $held[] = $id;
            }
            if ($hardGap && $dates->gameDateTimeUtc($game->game_date, $game->game_time)->lte($now->addHours(24))) {
                $blocking[] = $id;
            }
        }

        $status = ((! $enabled && $ids !== []) || $blocking !== []) ? 'failing'
            : (($missing !== [] || $stale !== [] || $partial !== [] || $unlinked !== [] || $uncited !== [] || $held !== []) ? 'warning' : 'passing');

        return [
            'check_type' => 'validation_nfl_research_coverage',
            'status' => $status,
            'severity' => $status,
            'message' => ! $enabled && $ids !== []
                ? 'NFL research is disabled while upcoming games require evidence coverage.'
                : "{$covered}/".count($ids).' upcoming NFL games have fresh cited research and revision evidence; '.count($held).' game(s) have explicit data holds.',
            'recommended_action' => 'nfl:research-pipeline --days-forward='.$days.' --limit=4',
            'metadata' => [
                'enabled' => $enabled, 'window_days' => $days, 'eligible_games' => count($ids), 'eligible_game_ids' => $ids, 'covered_games' => $covered,
                'missing_game_ids' => $missing, 'stale_game_ids' => $stale, 'partial_game_ids' => $partial,
                'unlinked_revision_game_ids' => $unlinked, 'uncited_game_ids' => $uncited,
                'data_hold_game_ids' => $held, 'blocking_game_ids' => $blocking,
                'near_kickoff_hours' => 24,
            ],
        ];
    }

    private function hasCitations(object $report): bool
    {
        $sources = json_decode($report->sources ?? '[]', true);

        return collect(is_array($sources) ? $sources : [])->contains(fn ($source): bool => is_array($source) && is_string($source['url'] ?? null)
            && filter_var($source['url'], FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($source['url'], PHP_URL_SCHEME)), ['http', 'https'], true)
        );
    }
}
