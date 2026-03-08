<?php

namespace App\Listeners;

use App\Actions\CBB\GradePredictions as CbbGradePredictions;
use App\Actions\CFB\GradePredictions as CfbGradePredictions;
use App\Actions\GradePlayerProps;
use App\Actions\MLB\GradePredictions as MlbGradePredictions;
use App\Actions\NBA\GradePredictions as NbaGradePredictions;
use App\Actions\NFL\GradePredictions as NflGradePredictions;
use App\Actions\WCBB\GradePredictions as WcbbGradePredictions;
use App\Actions\WNBA\GradePredictions as WnbaGradePredictions;
use App\Events\GameFinalized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class TriggerGameFinalizationGrading implements ShouldQueue
{
    /**
     * @var array<string, class-string>
     */
    private const PREDICTION_GRADER_BY_SPORT = [
        'nba' => NbaGradePredictions::class,
        'nfl' => NflGradePredictions::class,
        'mlb' => MlbGradePredictions::class,
        'cbb' => CbbGradePredictions::class,
        'cfb' => CfbGradePredictions::class,
        'wnba' => WnbaGradePredictions::class,
        'wcbb' => WcbbGradePredictions::class,
    ];

    /**
     * @var array<string, string>
     */
    private const PLAYER_PROP_SPORT_KEY_BY_SPORT = [
        'nba' => 'basketball_nba',
        'cbb' => 'basketball_ncaab',
        'nfl' => 'americanfootball_nfl',
        'mlb' => 'baseball_mlb',
    ];

    public function middleware(GameFinalized $event): array
    {
        $lockKey = "grade-finalized-game:{$event->sport}:{$event->gameId}";

        return [
            (new WithoutOverlapping($lockKey))->expireAfter(180),
        ];
    }

    public function handle(GameFinalized $event): void
    {
        $predictionGraderClass = self::PREDICTION_GRADER_BY_SPORT[$event->sport] ?? null;

        if ($predictionGraderClass) {
            app($predictionGraderClass)->executeForGame($event->gameId);
        }

        $playerPropSportKey = self::PLAYER_PROP_SPORT_KEY_BY_SPORT[$event->sport] ?? null;
        if ($playerPropSportKey) {
            app(GradePlayerProps::class)->executeForGame($playerPropSportKey, $event->gameId);
        }
    }
}
