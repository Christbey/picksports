<?php

namespace App\Services\MLB;

use App\Events\GameFinalized;
use App\Models\MLB\Game;
use App\Models\MlbGameReconciliationRecord;
use App\Services\ESPN\MLB\EspnService;
use Illuminate\Support\Facades\DB;

class MlbStaleGameReconciler
{
    public const ACTIVE_STATUSES = ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'STATUS_IN_PROGRESS', 'STATUS_END_PERIOD', 'STATUS_SUSPENDED'];

    public function __construct(private readonly EspnService $provider) {}

    /** @return array<string, mixed> */
    public function reconcile(Game $game, bool $apply = false, bool $holdUnresolved = false): array
    {
        if (! in_array($game->status, self::ACTIVE_STATUSES, true) || $game->game_date?->gte(today()->subDays(2))) {
            return ['game_id' => $game->id, 'outcome' => 'skipped', 'reason' => 'not_stale'];
        }
        $before = $game->only(['status', 'home_score', 'away_score', 'inning']);
        $data = $this->provider->getGame((string) $game->espn_event_id);
        $competition = data_get($data, 'header.competitions.0', []);
        $competitors = collect($competition['competitors'] ?? []);
        $home = $competitors->firstWhere('homeAway', 'home');
        $away = $competitors->firstWhere('homeAway', 'away');
        $identity = (string) data_get($data, 'header.id') === (string) $game->espn_event_id
            && $home !== null && $away !== null
            && (string) data_get($home, 'team.id') === (string) $game->homeTeam?->espn_id
            && (string) data_get($away, 'team.id') === (string) $game->awayTeam?->espn_id;
        $status = (string) data_get($competition, 'status.type.name');
        $reason = ! $identity ? 'provider_identity_unavailable' : 'provider_not_terminal';
        $after = $before;
        $outcome = 'held';
        $terminal = $identity && in_array($status, ['STATUS_FINAL', 'STATUS_CANCELED', 'STATUS_POSTPONED'], true);
        if ($terminal && $status === 'STATUS_FINAL') {
            $scores = [data_get($home, 'score'), data_get($away, 'score')];
            $valid = data_get($competition, 'status.type.completed') === true;
            foreach ($scores as $score) {
                $valid = $valid && is_numeric($score) && (float) $score >= 0 && floor((float) $score) === (float) $score;
            }
            if (! $valid || (float) $scores[0] === (float) $scores[1]) {
                $terminal = false;
                $reason = 'final_score_unverified';
            } else {
                $after['home_score'] = (int) $scores[0];
                $after['away_score'] = (int) $scores[1];
                $after['inning'] = is_numeric(data_get($competition, 'status.period')) ? (int) data_get($competition, 'status.period') : $game->inning;
            }
        }
        if ($terminal) {
            $outcome = 'reconciled';
            $reason = 'provider_terminal_status';
            $after['status'] = $status;
        } elseif ($holdUnresolved) {
            $after['status'] = 'STATUS_UNRESOLVED';
        }
        $evidence = ['provider' => 'espn', 'event_id' => data_get($data, 'header.id'),
            'competition_id' => $competition['id'] ?? null, 'observed_at' => now()->toIso8601String(),
            'provider_date' => $competition['date'] ?? null, 'provider_status' => $status,
            'home_team_id' => data_get($home, 'team.id'), 'away_team_id' => data_get($away, 'team.id'),
            'home_score' => data_get($home, 'score'), 'away_score' => data_get($away, 'score'),
            'completed' => data_get($competition, 'status.type.completed')];
        if ($apply && $after !== $before) {
            DB::transaction(function () use ($game, $before, $after, $outcome, $reason, $evidence): void {
                $locked = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
                if ($locked->only(array_keys($before)) !== $before) {
                    throw new \RuntimeException('MLB game changed during reconciliation; retry from current state.');
                }
                $locked->forceFill($after)->save();
                $locked->sportEvent?->forceFill(['status' => $after['status']])->save();
                MlbGameReconciliationRecord::create(['game_id' => $game->id, 'provider_event_id' => $game->espn_event_id,
                    'outcome' => $outcome, 'reason' => $reason, 'before' => $before, 'after' => $after, 'evidence' => $evidence]);
                if ($after['status'] === 'STATUS_FINAL') {
                    DB::afterCommit(fn () => GameFinalized::dispatch('mlb', $game->id, $game->season, Game::class));
                }
            });
        }

        return ['game_id' => $game->id, 'outcome' => $outcome, 'reason' => $reason, 'before' => $before, 'after' => $after, 'applied' => $apply && $after !== $before];
    }
}
