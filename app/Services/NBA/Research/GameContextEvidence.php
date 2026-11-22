<?php

namespace App\Services\NBA\Research;

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\ResearchContextSnapshot;
use App\Models\NBA\Team;
use App\Services\NBA\Injuries\InjuryCanaryScope;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Research\ResearchDocumentStore;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/** Descriptive, stored NBA context. Historical minutes and projected depth are never confirmed lineups. */
class GameContextEvidence
{
    public const VERSION = 'nba-context-v2';

    public function build(Game $game, ?CarbonInterface $cutoff, array $availability = []): array
    {
        $r = ['facts' => [], 'concerns' => [], 'sources' => [], 'unresolved' => [],
            'coverage' => array_fill_keys(['lineup', 'rotation', 'rest_travel'], ['home' => false, 'away' => false])];
        if (! app(InjuryCanaryScope::class)->allowsGame($game) || ! $cutoff || $game->sportEvent?->sport !== 'nba'
            || (int) $game->season !== (int) $game->sportEvent->season || (string) $game->season_type !== (string) $game->sportEvent->season_type) {
            return $r;
        }
        $packet = Schema::hasTable('nba_research_context_snapshots')
            ? ResearchContextSnapshot::query()->with('source')->where('game_id', $game->id)->latest('id')->first() : null;
        if ($packet && ($packet->identity_hash !== CaptureGameContext::identity($game) || ! $packet->source?->enabled
            || $packet->source->sport !== 'nba' || $packet->source->source_key !== CaptureGameContext::sourceKey($game)
            || $packet->source->kind !== 'structured_espn')) {
            $packet = null;
        }
        foreach (['home' => $game->home_team_id, 'away' => $game->away_team_id] as $side => $teamId) {
            $team = Team::query()->findOrFail($teamId);
            $players = Player::query()->where('team_id', $teamId)->get()->keyBy('espn_id');
            $base = ['team_side' => $side, 'evidence_kind' => 'application', 'evidence_status' => 'reference_verified',
                'provider' => 'picksports', 'publisher' => 'PickSports', 'parser_version' => self::VERSION,
                'source_label' => 'PickSports stored ESPN schedule and box scores', 'source_scope' => 'historical_team_context',
                'source_url' => rtrim(config('app.url'), '/').'/api/v2/sports/nba/teams/'.$teamId.'/games',
                'observed_at' => null, 'published_at' => null,
                'interpretation' => 'Derived from stored completed games; not a new provider observation or a prediction of tonight’s lineup, minutes or actual travel.'];
            $past = app(RecentGames::class)->before($game, $teamId, $cutoff);
            $last = $past->first();
            if ($last && $last->status === 'STATUS_FINAL' && (! $last->completed_at || ($last->completed_at->lt($cutoff) && $last->completed_at->lte(now())))) {
                $start = $this->start($last);
                $days = (int) $start->setTimezone('America/Chicago')->startOfDay()->diffInDays($cutoff->copy()->setTimezone('America/Chicago')->startOfDay());
                $rest = max(0, $days - 1);
                $venues = $last->venue_city && $game->venue_city ? $last->venue_city.' → '.$game->venue_city : null;
                $r['facts'][] = [...$base, 'topic' => 'rest_travel', 'claim' => 'Stored schedule (Central calendar): '.$rest.' full calendar rest day(s), '.round($start->diffInHours($cutoff), 1).' hours between tipoffs; '.($venues ? 'venue sequence '.$venues.'.' : 'venue sequence unavailable.'),
                    'evidence_reference' => 'nba_games:'.$last->id.',nba_games:'.$game->id, 'json_pointer' => '/game_date,/game_time,/venue_city'];
                $r['coverage']['rest_travel'][$side] = $venues !== null;
                if ($venues === null) {
                    $r['unresolved'][] = $this->gap($side, 'rest_travel', 'venue_sequence_missing');
                }
            } else {
                $r['unresolved'][] = $this->gap($side, 'rest_travel', 'preceding_game_not_verified_complete');
            }
            $hasRawHistory = $packet && collect(array_keys($packet->responses))->contains(fn ($key) => str_starts_with($key, $side.'_history_'));
            $sample = $past->take($hasRawHistory ? 3 : 5);
            $totals = [];
            $refs = [];
            $contentRefs = [];
            $valid = $sample->count() >= 3;
            foreach ($sample as $prior) {
                if ($prior->status !== 'STATUS_FINAL' || ($prior->completed_at && ($prior->completed_at->gte($cutoff) || $prior->completed_at->isFuture()))) {
                    $valid = false;

                    continue;
                }
                $historyKey = $side.'_history_'.$prior->id;
                if ($hasRawHistory && array_key_exists($historyKey, $packet->responses)) {
                    $raw = $this->response($packet, $historyKey, $cutoff);
                    $minutes = $raw ? $this->boxscoreMinutes($raw['payload'], $prior, $team, $players) : null;
                    if ($minutes === null) {
                        $valid = false;

                        continue;
                    }
                    $sum = array_sum($minutes);
                    $expected = 240 + max(0, (int) $prior->period - 4) * 25;
                    if ($sum < $expected - 3 || $sum > $expected + 3 || count(array_filter($minutes)) < 5) {
                        $valid = false;
                    }
                    foreach ($minutes as $id => $value) {
                        $totals[$id] = ($totals[$id] ?? 0) + $value;
                    }
                    // Content identity must survive a new immutable receipt of the same raw body.
                    $contentRefs[] = ['game_id' => $prior->id, 'provider_event_id' => $prior->espn_event_id,
                        'content_hash' => app(CanonicalPayloadHasher::class)->hash($raw['payload'])];
                    $refs[] = 'nba_research_context_snapshots:'.$packet->id.'#/responses/'.$historyKey.'/payload/boxscore/players';
                    $r['sources'][] = $this->source($packet, $raw);

                    continue;
                }
                $stats = PlayerStat::query()->where('game_id', $prior->id)->where('team_id', $teamId)->orderBy('player_id')->get();
                $minutes = [];
                $sum = 0;
                foreach ($stats as $stat) {
                    $value = $this->minutes($stat->minutes_played);
                    if ($value === null || ! $stat->updated_at || $stat->updated_at->gt($cutoff) || $stat->updated_at->isFuture()) {
                        $valid = false;

                        continue;
                    }
                    $sum += $value;
                    $minutes[$stat->player_id] = $value;
                    $refs[] = 'nba_player_stats:'.$stat->id;
                    $contentRefs[] = ['stat_id' => $stat->id, 'game_id' => $prior->id, 'player_id' => $stat->player_id, 'minutes' => $value];
                }
                $expected = 240 + max(0, (int) $prior->period - 4) * 25;
                if ($sum < $expected - 3 || $sum > $expected + 3 || $stats->count() < 5) {
                    $valid = false;
                }
                foreach ($minutes as $id => $value) {
                    $totals[$id] = ($totals[$id] ?? 0) + $value;
                }
            }
            $current = $players->keyBy('id');
            if (array_diff(array_keys(array_filter($totals)), $current->keys()->all()) !== []) {
                $valid = false;
            }
            if ($valid) {
                arsort($totals);
                $leaders = [];
                foreach (array_slice($totals, 0, 8, true) as $id => $total) {
                    if ($total <= 0) {
                        continue;
                    }
                    $leaders[] = ($current[$id]->full_name ?? 'Player '.$id).' '.round($total / $sample->count(), 1).' minutes/team game';
                }
                $r['facts'][] = [...$base, 'topic' => 'rotation', 'claim' => 'Recent rotation over '.$sample->count().' completed team games (absence counts as zero): '.implode('; ', $leaders).'.',
                    'evidence_reference' => implode(',', $refs), 'json_pointer' => '/minutes_played'];
                $r['coverage']['rotation'][$side] = true;
            } else {
                $r['unresolved'][] = $this->gap($side, 'rotation', 'recent_boxscore_or_roster_coverage_incomplete');
            }
            $summary = $this->response($packet, 'summary', $cutoff);
            $starters = [];
            $header = $summary['payload']['header'] ?? [];
            $competition = $header['competitions'][0] ?? [];
            $providerTeams = array_map('strval', array_column(array_column($competition['competitors'] ?? [], 'team'), 'id'));
            $expectedTeams = Team::query()->whereIn('id', [$game->home_team_id, $game->away_team_id])->pluck('espn_id')->map('strval')->all();
            sort($providerTeams);
            sort($expectedTeams);
            try {
                $summaryTime = is_string($competition['date'] ?? null) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $competition['date']) ? CarbonImmutable::parse($competition['date']) : null;
            } catch (\Throwable) {
                $summaryTime = null;
            }
            $identityValid = (string) ($header['id'] ?? '') === (string) $game->espn_event_id && $providerTeams === $expectedTeams
                && $summaryTime !== null && abs($summaryTime->timestamp - $cutoff->timestamp) <= 60;
            if ($summary && $identityValid) {
                foreach (is_array($summary['payload']['boxscore']['players'] ?? null) ? $summary['payload']['boxscore']['players'] : [] as $section) {
                    if ((string) ($section['team']['id'] ?? '') !== (string) $team->espn_id) {
                        continue;
                    }
                    foreach (is_array($section['statistics'][0]['athletes'] ?? null) ? $section['statistics'][0]['athletes'] : [] as $row) {
                        if (($row['starter'] ?? null) === true) {
                            $starters[] = (string) ($row['athlete']['id'] ?? '');
                        }
                    }
                }
            }
            $confirmed = count($starters) === 5 && count(array_unique($starters)) === 5 && count(array_diff($starters, $players->keys()->map('strval')->all())) === 0;
            if ($confirmed) {
                $r['facts'][] = $this->lineup($side, $players, $starters, $packet, $summary, true);
                $r['coverage']['lineup'][$side] = true;
                $r['sources'][] = $this->source($packet, $summary);
            } else {
                $depth = $this->response($packet, $side.'_depth', $cutoff);
                $projected = [];
                $body = $depth['payload'] ?? [];
                if (is_array($body['items'] ?? null) && array_is_list($body['items']) && count($body['items']) === 1 && (int) ($body['count'] ?? -1) === 1 && (int) ($body['pageCount'] ?? 1) <= 1) {
                    foreach (is_array($body['items'][0]['positions'] ?? null) ? $body['items'][0]['positions'] : [] as $position) {
                        foreach (is_array($position['athletes'] ?? null) ? $position['athletes'] : [] as $row) {
                            if (($row['rank'] ?? null) == 1) {
                                $athlete = $row['athlete'] ?? [];
                                $id = $athlete['id'] ?? null;
                                if ($id === null && preg_match('#/athletes/(\d+)#', $athlete['$ref'] ?? '', $match)) {
                                    $id = $match[1];
                                }
                                $projected[] = (string) $id;
                            }
                        }
                    }
                }
                if (count($projected) === 5 && count(array_unique($projected)) === 5 && array_diff($projected, $players->keys()->map('strval')->all()) === []) {
                    $r['facts'][] = $this->lineup($side, $players, $projected, $packet, $depth, false);
                    $r['coverage']['lineup'][$side] = true;
                    $r['sources'][] = $this->source($packet, $depth);
                    foreach ($availability as $finding) {
                        if (($finding['team_side'] ?? null) === $side && in_array($finding['espn_athlete_id'] ?? '', $projected, true)
                            && in_array($finding['normalized_status'] ?? '', ['reported_out', 'reported_doubtful'], true)) {
                            $r['concerns'][] = [...$finding, 'topic' => 'lineup', 'evidence_reference' => $finding['evidence_reference'].',nba_research_context_snapshots:'.$packet->id, 'claim' => 'A projected depth-chart starter is reported '.$finding['raw_status'].'; the projected five must not be treated as confirmed.'];
                            $r['unresolved'][] = $this->gap($side, 'lineup', 'projected_lineup_availability_conflict');
                        }
                    }
                }
                $r['unresolved'][] = $this->gap($side, 'lineup', 'game_starting_five_not_confirmed');
            }
            if ($last || $valid) {
                $r['sources'][] = ['url' => $base['source_url'], 'title' => $base['source_label'], 'publisher' => 'PickSports', 'provider' => 'picksports',
                    'source_scope' => 'historical_team_context', 'parser_version' => self::VERSION, 'observed_at' => null, 'published_at' => null,
                    'content_hash' => app(CanonicalPayloadHasher::class)->hash([$last?->only(['id', 'season', 'season_type', 'status', 'game_date', 'game_time', 'venue_city']), $totals, $contentRefs])];
            }
        }

        return $r;
    }

    private function response(?ResearchContextSnapshot $packet, string $key, CarbonInterface $cutoff): ?array
    {
        $r = $packet?->responses[$key] ?? null;
        if (! $r || $r['error_code'] !== null || ! $r['fetched_at'] || ! is_array($r['payload'])
            || ! app(ResearchDocumentStore::class)->allowed($packet->source, $r['url'])) {
            return null;
        }
        $at = CarbonImmutable::parse($r['fetched_at']);

        return $at->lte(now()) && $at->gte(now()->subMinutes(120)) && $at->lt($cutoff) ? $r : null;
    }

    private function boxscoreMinutes(array $body, Game $prior, Team $team, $players): ?array
    {
        $header = $body['header'] ?? [];
        $competition = $header['competitions'][0] ?? [];
        try {
            $date = is_string($competition['date'] ?? null) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $competition['date']) ? CarbonImmutable::parse($competition['date']) : null;
        } catch (\Throwable) {
            return null;
        }
        $ids = array_map('strval', array_column(array_column($competition['competitors'] ?? [], 'team'), 'id'));
        $expected = Team::query()->whereIn('id', [$prior->home_team_id, $prior->away_team_id])->pluck('espn_id')->map('strval')->all();
        sort($ids);
        sort($expected);
        if ((string) ($header['id'] ?? '') !== (string) $prior->espn_event_id || $ids !== $expected || ! $date
            || abs($date->timestamp - $this->start($prior)->timestamp) > 60 || ($competition['status']['type']['name'] ?? '') !== 'STATUS_FINAL') {
            return null;
        }
        foreach (is_array($body['boxscore']['players'] ?? null) ? $body['boxscore']['players'] : [] as $section) {
            if ((string) ($section['team']['id'] ?? '') !== (string) $team->espn_id) {
                continue;
            }
            $group = $section['statistics'][0] ?? [];
            $labels = $group['labels'] ?? $group['names'] ?? [];
            $index = collect($labels)->search(fn ($label) => in_array(strtolower((string) $label), ['min', 'minutes'], true));
            if ($index === false || ! is_array($group['athletes'] ?? null)) {
                return null;
            }
            $minutes = [];
            $seen = [];
            foreach ($group['athletes'] as $row) {
                $id = (string) ($row['athlete']['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    return null;
                } $seen[$id] = true;
                $value = ($row['didNotPlay'] ?? false) === true ? 0.0 : $this->minutes($row['stats'][$index] ?? null);
                if ($value === null || ($value > 0 && ! isset($players[$id]))) {
                    return null;
                }
                if (isset($players[$id])) {
                    $minutes[$players[$id]->id] = $value;
                }
            }

            return $minutes;
        }

        return null;
    }

    private function lineup(string $side, $players, array $ids, ResearchContextSnapshot $packet, array $response, bool $confirmed): array
    {
        return ['topic' => 'lineup', 'team_side' => $side, 'claim' => ($confirmed ? 'ESPN event-reported starters: ' : 'Projected ESPN depth-chart starters, not confirmed for this game: ')
            .implode(', ', array_map(fn ($id) => $players[$id]->full_name ?? 'ESPN athlete '.$id, $ids)).'.',
            'interpretation' => 'Provider-reported structured fields; not an official NBA lineup and not a source quotation.',
            'evidence_kind' => 'structured', 'evidence_status' => 'reference_verified', 'source_url' => $response['url'],
            'source_label' => $confirmed ? 'ESPN event starter flags' : 'ESPN current-season depth projection', 'provider' => 'espn', 'publisher' => 'ESPN',
            'source_scope' => $confirmed ? 'event_reported_lineup' : 'projected_depth_chart', 'parser_version' => self::VERSION,
            'evidence_reference' => 'nba_research_context_snapshots:'.$packet->id, 'json_pointer' => $confirmed ? '/responses/summary/payload/boxscore/players' : '/responses/'.$side.'_depth/payload/items/0/positions',
            'snapshot_id' => $packet->id, 'observed_at' => $response['fetched_at'], 'published_at' => null];
    }

    private function source(ResearchContextSnapshot $packet, array $response): array
    {
        return ['url' => $response['url'], 'title' => 'ESPN NBA structured game context', 'publisher' => 'ESPN', 'provider' => 'espn',
            'source_scope' => 'game_context', 'parser_version' => self::VERSION, 'snapshot_id' => $packet->id,
            'content_hash' => app(CanonicalPayloadHasher::class)->hash($response['payload']), 'observed_at' => $response['fetched_at'], 'published_at' => null];
    }

    private function gap(string $side, string $topic, string $reason): array
    {
        return ['topic' => $topic, 'team_side' => $side, 'claim' => str_replace('_', ' ', $reason).'.', 'reason_code' => $reason,
            'blocking' => false, 'scope' => 'research', 'evidence_status' => 'unavailable'];
    }

    private function start(Game $game): ?CarbonInterface
    {
        return $game->sportEvent?->starts_at ?? app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
    }

    private function minutes(mixed $value): ?float
    {
        if (is_numeric($value) && (float) $value >= 0 && (float) $value <= 72) {
            return (float) $value;
        }
        if (is_string($value) && preg_match('/^(\d{1,2}):([0-5]\d)$/', $value, $m) && (int) $m[1] <= 72) {
            return (int) $m[1] + (int) $m[2] / 60;
        }

        return null;
    }
}
