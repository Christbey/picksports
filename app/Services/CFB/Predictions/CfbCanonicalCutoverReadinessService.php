<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Services\Predictions\CanonicalSportCutoverReadinessService;
use App\Services\Sports\CfbGameCalendarRead;
use App\Support\CFB\CfbGameStart;
use Carbon\CarbonImmutable;
use LengthException;

class CfbCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string,mixed> */
    public function report(?int $season = null, ?int $week = null): array
    {
        $unknown = [];
        $mismatched = [];
        $games = Game::query()->useWritePdo()->setEagerLoads([])
            ->whereIn('cfb_games.status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->when($season !== null, fn ($query) => $query->where('cfb_games.season', $season))
            ->when($week !== null, fn ($query) => $query->where('cfb_games.week', $week))
            ->leftJoin('sport_events as readiness_event', 'readiness_event.id', '=', 'cfb_games.sport_event_id')
            ->select(['cfb_games.id', 'cfb_games.game_date', 'cfb_games.game_time', 'cfb_games.sport_event_id',
                'readiness_event.sport as event_sport', 'readiness_event.starts_at as event_starts_at']);
        $bytes = $games->getConnection()->getDriverName() === 'mysql'
            ? 'OCTET_LENGTH(cfb_games.espn_event_id)' : 'LENGTH(CAST(cfb_games.espn_event_id AS BLOB))';
        $games->selectRaw("CASE WHEN $bytes <= 64 THEN cfb_games.espn_event_id ELSE NULL END AS espn_event_id");
        $games = app(CfbGameCalendarRead::class)->withBoundedObservation($games);
        $count = 0;
        // Scheduled legacy carriers may be old placeholders. They cannot establish
        // exclusion from the current scope; finals and other requested seasons/weeks are excluded above.
        foreach ($games->lazyById(8, 'cfb_games.id', 'id') as $game) {
            if (++$count > 10000) {
                throw new LengthException('CFB readiness candidate capacity exceeded; no partial report was returned.');
            }
            $start = CfbGameStart::for($game);
            if ($start === null) {
                $unknown[] = (int) $game->id;
            } elseif ($game->sport_event_id === null || $game->event_sport !== 'cfb'
                || $game->event_starts_at === null
                || ! $start->equalTo(CarbonImmutable::parse($game->event_starts_at, (string) config('app.timezone', 'UTC')))) {
                $mismatched[] = (int) $game->id;
            }
            if (count($unknown) + count($mismatched) > 1000) {
                throw new LengthException('CFB readiness hold capacity exceeded; no partial report was returned.');
            }
        }
        $report = $this->readiness->report('cfb', $season, $week);
        $report['unknown_current_kickoff_game_ids'] = $unknown;
        $report['current_event_identity_hold_game_ids'] = $mismatched;
        if ($unknown !== [] || $mismatched !== []) {
            $report['ready_for_cutover'] = false;
            $report['next_action'] = 'Verify the listed scheduled CFB games against their current provider identity and confirmed kickoff, then reconcile their canonical event bindings. Preserve historical forecasts; never invent kickoff or backfill a native pregame forecast after the game.';
        }

        return $report;
    }
}
