<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\CFB\Elo\EloRebuildService;
use Illuminate\Database\Eloquent\Builder;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'cfb:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate CFB team Elo ratings based on completed games';

    protected const SPORT_NAME = 'CFB';

    protected const EXTRA_SIGNATURE_OPTIONS = [
        '{--regress : Apply offseason regression toward mean before calculating}',
    ];

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;

    protected function applyAdditionalAnalyticsFilters(Builder $query): void
    {
        $query->reorder()->orderBy('season')->orderBy('game_date')->orderBy('game_time')->orderBy('id');
    }

    public function handle(): int
    {
        if ($this->option('reset')) {
            $this->error('Destructive reset is disabled for CFB. Use cfb:rebuild-elo to review an isolated candidate.');

            return self::FAILURE;
        }
        if ($this->option('regress')) {
            $this->warn('Season regression is now automatic and idempotent per team; --regress is unnecessary.');
            $this->input->setOption('regress', false);
        }
        try {
            app(EloRebuildService::class)->assertActiveHistoryCurrent();

            return parent::handle();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
