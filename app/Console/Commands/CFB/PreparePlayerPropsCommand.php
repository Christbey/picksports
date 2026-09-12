<?php

namespace App\Console\Commands\CFB;

use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Actions\ESPN\CFB\SyncPlayers;
use App\Actions\ESPN\CFB\SyncPlayerStats;
use App\Actions\GradePlayerProps;
use App\Models\CFB\Game;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\Team;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\ESPN\CFB\EspnService;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PreparePlayerPropsCommand extends Command
{
    protected $signature = 'cfb:prepare-player-props {--date= : Central game date; defaults to today} {--game= : Internal game ID} {--games-per-team=6 : Recent final games to backfill, maximum 16} {--refresh : Refresh previously imported history and rosters}';

    protected $description = 'Refresh prop-team rosters and injuries, backfill recent box scores, link players, and score college football props';

    public function handle(EspnService $espn, PlayerPropAnalyzer $analyzer): int
    {
        $date = $this->option('date') ?? now('America/Chicago')->toDateString();
        $validator = Validator::make(['date' => $date, 'game' => $this->option('game'), 'limit' => $this->option('games-per-team')],
            ['date' => 'required|date_format:Y-m-d', 'game' => 'nullable|integer|min:1', 'limit' => 'required|integer|min:3|max:16']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $query = Game::where('status', 'STATUS_SCHEDULED')->whereHas('playerProps', fn ($q) => $q->where('is_current', true));
        CfbPropEligibility::onDate($query, $date);
        $games = $query->when($this->option('game'), fn ($q, $id) => $q->whereKey($id))->get()
            ->filter(fn ($game) => CfbPropEligibility::kickoff($game)->isFuture());
        $teamIds = $games->flatMap(fn ($game) => [$game->home_team_id, $game->away_team_id])->unique();
        $counts = ['teams' => 0, 'roster_players' => 0, 'history_games' => 0, 'stat_rows' => 0, 'linked' => 0, 'recommendations' => 0, 'failed' => 0];
        $readyTeams = [];
        foreach (Team::whereIn('id', $teamIds)->get() as $team) {
            try {
                $cacheKey = 'cfb:prop-roster:'.$team->id.':'.now('America/Chicago')->toDateString();
                if ($this->option('refresh') || ! Cache::has($cacheKey)) {
                    $rosterCount = (new SyncPlayers($espn))->execute((string) $team->espn_id);
                    if ($rosterCount === 0) {
                        $counts['failed']++;

                        continue;
                    }
                    $counts['roster_players'] += $rosterCount;
                    Cache::put($cacheKey, true, now()->addHours(24));
                }
                (new SyncPlayerInjuries($espn))->execute((string) $team->espn_id);
                $readyTeams[] = $team->id;
                $counts['teams']++;
            } catch (\Throwable) {
                $counts['failed']++;
                $this->warn("Roster/injury preparation failed for team {$team->id}.");
            }
        }
        $historyIds = collect();
        foreach ($games as $game) {
            foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
                if (! in_array($teamId, $readyTeams, true)) {
                    continue;
                }
                $historyIds = $historyIds->merge(Game::where('status', 'STATUS_FINAL')
                    ->whereDate('game_date', '<', $game->game_date)->where('season', '>=', (int) $game->season - 1)
                    ->whereIn('season_type', [2, 3])
                    ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
                    ->orderByDesc('game_date')->limit((int) $this->option('games-per-team'))->pluck('id'));
            }
        }
        foreach (Game::whereIn('id', $historyIds->unique())->get() as $history) {
            $relevantTeams = array_values(array_intersect([$history->home_team_id, $history->away_team_id], $readyTeams));
            $populatedTeams = $history->playerStats()->whereIn('team_id', $relevantTeams)->distinct()->pluck('team_id')->count();
            if (! $this->option('refresh') && $populatedTeams === count($relevantTeams)) {
                continue;
            }
            try {
                $payload = $espn->getGame((string) $history->espn_event_id);
                if (! is_array($payload) || empty($payload['boxscore']['players'])) {
                    $counts['failed']++;

                    continue;
                }
                $rows = DB::transaction(fn () => (new SyncPlayerStats)->execute($payload, $history));
                $counts['history_games']++;
                $counts['stat_rows'] += $rows;
                $counts['failed'] += $rows === 0 ? 1 : 0;
                app(GradePlayerProps::class)->executeForGame('americanfootball_ncaaf', $history->id);
            } catch (\Throwable) {
                $counts['failed']++;
                $this->warn("History import failed for game {$history->id}.");
            }
        }
        foreach ($games as $game) {
            foreach (PlayerProp::where('game_id', $game->id)->whereNull('graded_at')->where('is_current', true)->get() as $prop) {
                $player = CfbPropEligibility::matchPlayer($game, $prop->player_name);
                $prop->update(['player_id' => $player && in_array($player->team_id, $readyTeams, true) ? $player->id : null]);
                $counts['linked'] += $prop->player_id ? 1 : 0;
            }
            $counts['recommendations'] += $analyzer->analyzeProps(sport: 'CFB', gameFilter: $game->id, attachNarratives: false)->count();
        }
        app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
