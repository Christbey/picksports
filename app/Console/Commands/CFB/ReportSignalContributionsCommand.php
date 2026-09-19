<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Services\CFB\Predictions\CfbSignalContributionGrader;
use App\Services\CFB\Predictions\CfbStoredPregameQuote;
use Illuminate\Console\Command;

class ReportSignalContributionsCommand extends Command
{
    protected $signature = 'cfb:report-signal-contributions {--season=} {--days-forward=2}';

    protected $description = 'Grade individual CFB signal contributions using frozen pregame forecasts and paired counterfactuals';

    public function handle(CfbSignalContributionGrader $grader, CfbStoredPregameQuote $quotes): int
    {
        $season = (string) $this->option('season');
        $days = (string) $this->option('days-forward');
        if (! ctype_digit($season) || (int) $season < 2000 || (int) $season > 2100
            || ! ctype_digit($days) || (int) $days < 1 || (int) $days > 8) {
            $this->error('Provide --season=YYYY and --days-forward=1..8.');

            return self::FAILURE;
        }
        $rows = $excluded = $summary = [];
        $games = Game::with(['sportEvent', 'homeTeam', 'awayTeam'])->where('season', $season)
            ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '<=', now()->addDays((int) $days)))
            ->orderBy('id')->get();
        foreach ($games as $game) {
            $kickoff = $game->sportEvent->starts_at;
            $prediction = CanonicalPrediction::with(['markets', 'calculationRun.release', 'calculationRun.inputSnapshot'])
                ->where('sport_event_id', $game->sport_event_id)->where('phase', 'pregame')
                ->whereIn('publication_state', ['published', 'superseded'])->where('generated_at', '<', $kickoff)
                ->orderByDesc('generated_at')->orderByDesc('revision')->first();
            if (! $prediction) {
                $excluded['no_stored_pregame_prediction'] = ($excluded['no_stored_pregame_prediction'] ?? 0) + 1;

                continue;
            }
            $final = $game->status === 'STATUS_FINAL' && is_numeric($game->home_score) && is_numeric($game->away_score);
            $asOf = $prediction->calculationRun?->inputSnapshot?->captured_at;
            $spread = $asOf ? $quotes->latest($game->id, 'spreads', 'home', $kickoff, $asOf) : null;
            $total = $asOf ? $quotes->latest($game->id, 'totals', 'over', $kickoff, $asOf) : null;
            $evaluation = $grader->evaluate($prediction, $kickoff->toImmutable(),
                $final ? (float) $game->home_score - $game->away_score : null,
                $final ? (float) $game->home_score + $game->away_score : null,
                is_numeric($spread?->line) ? (float) $spread->line : null,
                is_numeric($total?->line) ? (float) $total->line : null);
            if (isset($evaluation['excluded'])) {
                $excluded[$evaluation['excluded']] = ($excluded[$evaluation['excluded']] ?? 0) + 1;

                continue;
            }
            foreach ($evaluation['signals'] as $signal) {
                $key = $evaluation['release'].':'.$signal['market'].':'.$signal['signal'];
                $summary[$key] ??= ['observations' => 0, 'active' => 0, 'graded_active' => 0,
                    'helped' => 0, 'hurt' => 0, 'neutral' => 0, 'error_reduction_sum' => 0.0];
                $summary[$key]['observations']++;
                $summary[$key]['active'] += (int) $signal['active'];
                if ($signal['active'] && $signal['grade'] !== 'pending') {
                    $summary[$key]['graded_active']++;
                    $summary[$key][$signal['grade']]++;
                    $summary[$key]['error_reduction_sum'] += $signal['absolute_error_reduction'];
                }
            }
            $rows[] = ['game_id' => $game->id, 'home' => $game->homeTeam->school, 'away' => $game->awayTeam->school,
                'status' => $game->status, 'result_observed_at' => $final ? $game->updated_at->toIso8601String() : null,
                'spread_quote_id' => $spread?->id, 'total_quote_id' => $total?->id, ...$evaluation];
        }
        foreach ($summary as &$cohort) {
            $cohort['mean_absolute_error_reduction'] = $cohort['graded_active'] > 0
                ? round($cohort['error_reduction_sum'] / $cohort['graded_active'], 4) : null;
            unset($cohort['error_reduction_sum']);
        }
        unset($cohort);
        $this->line(json_encode(['as_of' => now()->toIso8601String(), 'season' => (int) $season,
            'version' => CfbSignalContributionGrader::VERSION, 'policy' => 'One latest stored pregame prediction per game; exact replay required; release cohorts kept separate; no automatic weight changes.',
            'limitations' => ['Counterfactual removal measures marginal contribution in this model, not causal or independent signal value.',
                'Correlated injury and rating features may overlap; contributions are not independent votes.',
                'Positive error reduction means the signal helped. Zero rounded contribution is neutral, not missing data.',
                'Market grades use only quotes stored by snapshot capture, not hindsight closing lines. No per-signal profit claims.',
                'Offense/defense counterfactuals replace scoring averages with the configured league prior; other signals remove their adjustment.',
                'Eligibility-only and unused fields are identified in feature_roles; no invented directional grade.',
                'Historical corrections are reflected in current final results. Pending games remain ungraded.'],
            'excluded' => $excluded, 'summary' => $summary, 'games' => $rows], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
