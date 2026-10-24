<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\ResearchRevision;
use App\Models\SportsGameContextReport;
use Illuminate\Console\Command;

class ResearchEvaluationCommand extends Command
{
    protected $signature = 'nfl:research-evaluation {--book=fanduel}';

    protected $description = 'Compare the latest graded pregame research revision per game against its original baseline';

    public function handle(): int
    {
        $rows = ResearchRevision::whereNotNull('graded_at')->latest('id')->get()->unique('game_id');
        $out = ['games' => $rows->count(), 'selection' => 'latest graded pregame revision per game', 'bookmaker' => $this->option('book'), 'variants' => []];
        foreach (['baseline', 'revised'] as $variant) {
            $values = $rows->map(fn ($r) => data_get($r->evaluation, 'variants.'.$variant))->filter();
            $quotes = $values->map(fn ($v) => collect($v['quotes'])->firstWhere('bookmaker', $this->option('book')))->filter();
            $out['variants'][$variant] = ['sample' => $values->count(), 'spread_mae' => $values->avg('spread_absolute_error'), 'total_mae' => $values->avg('total_absolute_error'), 'brier' => $values->whereNotNull('brier')->avg('brier'), 'hypothetical_quote_count' => $quotes->count(), 'hypothetical_units' => $quotes->sum('hypothetical_units'), 'hypothetical_roi' => $quotes->count() ? $quotes->sum('hypothetical_units') / $quotes->count() : null, 'mean_clv_points' => $quotes->whereNotNull('clv_points')->avg('clv_points')];
        }
        $out['eligible_games'] = $rows->filter(fn ($r) => data_get($r->evaluation, 'eligible_at_capture'))->count();
        $reports = SportsGameContextReport::where('sport', 'nfl')->where('researched_at', '>=', now()->subDays(30))
            ->latest('id')->limit(1000)->get(['id', 'game_id', 'status', 'model', 'raw_payload'])->unique('game_id');
        $audited = $reports->filter(fn ($report) => data_get($report->raw_payload, 'analysis_quality.version') === 1);
        $out['research_quality'] = [
            'selection' => 'Latest report per game within the newest 1000 reports from the last 30 days; separate from graded prediction evaluation.',
            'reports' => $reports->count(), 'audited_reports' => $audited->count(),
            'statuses' => $reports->countBy('status')->all(),
            'verified_references' => $audited->sum(fn ($report) => data_get($report->raw_payload, 'analysis_quality.verified_claims', 0)),
            'rejected_claims' => $audited->sum(fn ($report) => data_get($report->raw_payload, 'analysis_quality.rejected_claims', 0)),
            'unverified_questions' => $audited->sum(fn ($report) => data_get($report->raw_payload, 'analysis_quality.unverified_questions', 0)),
            'interpretation' => 'Reference checks verify stored passages and record paths, not semantic entailment, factual accuracy or betting value. Unnecessary holds and missed facts still require labeled review.',
        ];
        $out['interpretation'] = 'Descriptive paired comparison, not causal attribution. Returns include held model leans, not placed bets. Small samples do not establish profitability.';
        $this->line(json_encode($out, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
