<?php

namespace App\Console\Commands\Sports\Canonical;

use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractGenerateCollegeBasketballCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected function generationHorizon(): ?array
    {
        if (filled($this->option('game')) || filled($this->option('date'))) {
            return null;
        }

        return ['start' => now(), 'end' => now()->addDays(max(0, (int) $this->option('days-forward')))];
    }

    protected function applyDaysForwardOption(): bool
    {
        return false;
    }

    protected function generationHoldReason(Model $game): ?string
    {
        return app(CollegeBasketballPregameEligibility::class)->teamsAreKnown($game->homeTeam, $game->awayTeam)
            ? null : 'placeholder matchup requires known teams.';
    }
}
