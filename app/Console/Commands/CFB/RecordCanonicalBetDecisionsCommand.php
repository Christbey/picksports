<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Services\CFB\CfbReleasedBetDecisionRecorder;
use Illuminate\Console\Command;

class RecordCanonicalBetDecisionsCommand extends Command
{
    protected $signature = 'cfb:record-canonical-bet-decisions {--season=}';

    protected $description = 'Freeze fresh CFB model bets and held candidates prospectively; never place wagers';

    public function handle(CfbReleasedBetDecisionRecorder $recorder): int
    {
        $counts = ['recorded_or_existing' => 0, 'released_tracking_bets' => 0, 'held_candidates' => 0];
        $predictions = CanonicalPrediction::with(['sportEvent', 'markets', 'calculationRun.release', 'calculationRun.inputSnapshot'])
            ->where('sport', 'cfb')->where('phase', 'pregame')->where('publication_state', 'published')
            ->where('generated_at', '>=', now()->subMinutes(15))->where('generated_at', '<=', now())
            ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '>', now())
                ->when($this->option('season'), fn ($q, $season) => $q->where('season', (int) $season)))->get();
        foreach ($predictions as $prediction) {
            $game = Game::where('sport_event_id', $prediction->sport_event_id)->first();
            if ($game && ($decision = $recorder->record($prediction, $game))) {
                $counts['recorded_or_existing']++;
                $counts[$decision->is_bet ? 'released_tracking_bets' : 'held_candidates']++;
            }
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
