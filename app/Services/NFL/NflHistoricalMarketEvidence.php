<?php

namespace App\Services\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Collection;

/** Descriptive closing-line cohorts, never an input to the prediction pipeline. */
final class NflHistoricalMarketEvidence
{
    public const VERSION = 'nfl_historical_market_v1';

    public function build(Game $target, int $since = 2009, ?float $homeLine = null): array
    {
        $target->loadMissing(['homeTeam', 'awayTeam', 'sportEvent']);
        $market = $homeLine === null ? $this->targetMarket($target) : [
            'home_line' => $homeLine, 'source' => 'user_selected', 'bookmaker' => null,
            'snapshot_id' => null, 'observed_at' => null,
        ];
        // No trustworthy result-availability timestamp across the archive: exclude
        // the target date and later dates, including when reviewing an old matchup.
        $before = min($target->game_date?->toDateString() ?? now()->toDateString(), now()->toDateString());
        $games = Game::query()->whereBetween('season', [$since, (int) $target->season])
            ->where('season_type', '2')->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->whereDate('game_date', '<', $before)->whereKeyNot($target->id)
            ->orderBy('game_date')->orderBy('id')
            ->get(['id', 'season', 'game_date', 'home_team_id', 'away_team_id', 'home_score', 'away_score',
                'neutral_site', 'home_coach', 'away_coach', 'home_qb_id', 'away_qb_id', 'home_qb_name', 'away_qb_name']);
        $lines = $this->archiveLines($games->modelKeys());
        $observations = collect();
        foreach ($games as $game) {
            if (! isset($lines[$game->id])) {
                continue;
            }
            foreach (['home', 'away'] as $side) {
                $sign = $side === 'home' ? 1 : -1;
                $observations->push([
                    'game_id' => $game->id, 'snapshot_id' => $lines[$game->id]['snapshot_id'],
                    'date' => $game->game_date->toDateString(), 'team_id' => (int) $game->{$side.'_team_id'},
                    'venue' => $game->neutral_site === null ? null : ($game->neutral_site ? 'neutral' : $side),
                    'coach' => $this->identity($game->{$side.'_coach'}),
                    'qb_id' => $this->identity($game->{$side.'_qb_id'}),
                    'qb_name' => $this->identity($game->{$side.'_qb_name'}),
                    'line' => $sign * $lines[$game->id]['home_line'],
                    'margin' => $sign * ($game->home_score - $game->away_score),
                ]);
            }
        }

        $sides = [];
        foreach (['away', 'home'] as $side) {
            $line = $market === null ? null : ($side === 'home' ? 1 : -1) * $market['home_line'];
            $venue = $target->neutral_site === null ? null : ($target->neutral_site ? 'neutral' : $side);
            $coach = $this->identity($target->{$side.'_coach'});
            $qbId = $this->identity($target->{$side.'_qb_id'});
            $qbName = $this->identity($target->{$side.'_qb_name'});
            $exact = $line === null ? collect() : $observations->filter(fn ($row) => abs($row['line'] - $line) < 0.00001);
            $situation = $venue === null ? collect() : $exact->where('venue', $venue);
            $team = $exact->where('team_id', (int) $target->{$side.'_team_id'});
            $teamSituation = $venue === null ? collect() : $team->where('venue', $venue);
            $missingMarket = $line === null ? 'No verified target spread. Select a hypothetical home line to explore history.' : null;
            $missingVenue = $missingMarket ?? ($venue === null ? 'Target venue classification is missing.' : null);
            $coachGames = $coach === null ? collect() : $teamSituation->where('coach', $coach);
            $qbGames = $teamSituation->filter(fn ($row) => $qbId !== null
                ? $row['qb_id'] === $qbId
                : ($qbName !== null && $row['qb_name'] === $qbName));
            $sides[$side] = [
                'team' => $target->{$side.'Team'}?->abbreviation ?? ucfirst($side),
                'line' => $line, 'venue' => $venue,
                'coach' => $target->{$side.'_coach'}, 'qb' => $target->{$side.'_qb_name'} ?? $target->{$side.'_qb_id'},
                'qb_identity_source' => $qbId !== null ? 'game_provider_id' : ($qbName !== null ? 'game_exact_name' : null),
                'identity_coverage' => [
                    'cohort_games' => $teamSituation->count(),
                    'coach_known_games' => $teamSituation->whereNotNull('coach')->count(),
                    'qb_known_games' => $teamSituation->whereNotNull($qbId !== null ? 'qb_id' : 'qb_name')->count(),
                ],
                'rows' => [
                    $this->record('league', 'All teams · this exact line', $exact, $missingMarket),
                    $this->record('league_venue', 'All teams · same line & venue', $situation, $missingVenue),
                    $this->record('team', 'This team · this exact line', $team, $missingMarket),
                    $this->record('team_venue', 'This team · same line & venue', $teamSituation, $missingVenue),
                    $this->record('coach', 'Team + coach · same line & venue', $coachGames, $missingVenue ?? ($coach === null ? 'Game-specific coach is not recorded.' : null)),
                    $this->record('qb', 'Team + starting QB · same line & venue', $qbGames, $missingVenue ?? ($qbId === null && $qbName === null ? 'Game-specific starting QB is not recorded.' : null)),
                    $this->record('coach_qb', 'Team + coach + QB · same line & venue', $qbGames->where('coach', $coach), $missingVenue ?? ($coach === null || ($qbId === null && $qbName === null) ? 'Game-specific coach or starting QB is not recorded.' : null)),
                ],
            ];
        }

        return [
            'version' => self::VERSION, 'affects_prediction' => false, 'since_season' => $since,
            'target_season' => (int) $target->season, 'before_date' => $before, 'market' => $market,
            'coverage' => ['completed_games' => $games->count(), 'games_with_verified_line' => count($lines),
                'excluded_missing_or_conflicting_line' => $games->count() - count($lines),
                'from_date' => $observations->min('date'), 'through_date' => $observations->max('date')],
            'sides' => $sides,
            'limitations' => [
                'Regular seasons only. Exact signed closing spread; no nearby-line pooling. Negative = favorite, positive = underdog.',
                'History uses nflverse closing-line archives only. Missing or conflicting lines are excluded, not treated as losses. Current-season observed quotes are not mixed into closing-line cohorts.',
                'Archive lines are reconstructed historical evidence, not proof of what this application knew before kickoff. Synthetic archive capture timestamps are not observations.',
                'ATS = final scoring margin + that game’s actual handicap. Outright = final win/loss/tie; neither is a price-based moneyline return or ROI.',
                'Rows overlap. At pick’em, all-venue league rows contain both teams per game; those observations are not independent.',
                'Small samples and old coaching/roster eras can mislead. These records do not establish a calibrated probability or change model confidence.',
                'Coach/QB rows use game-recorded identities, never today’s depth chart. Team identities can span relocations. Name-only QB matches are labeled.',
            ],
        ];
    }

    /** One line per game, from the original source convention, not legacy reversed payloads. */
    private function archiveLines(array $ids): array
    {
        $lines = [];
        if ($ids === []) {
            return $lines;
        }
        $snapshots = GameOddsSnapshot::query()->where('sport', 'nfl')->where('game_table', 'nfl_games')
            ->where('source', 'nflverse')->where('bookmaker_key', 'nflverse_closing')
            ->whereIntegerInRaw('game_id', $ids)->orderBy('id')->get(['id', 'game_id', 'market_context']);
        foreach ($snapshots->groupBy('game_id') as $gameId => $group) {
            $valid = $group->filter(fn ($snapshot) => ($snapshot->market_context['source'] ?? null) === 'nflverse_schedules'
                && ($snapshot->market_context['line_type'] ?? null) === 'closing'
                && $this->validLine($snapshot->market_context['spread_line'] ?? null));
            $distinct = $valid->map(fn ($snapshot) => (float) $snapshot->market_context['spread_line'])->unique();
            if ($valid->count() === $group->count() && $distinct->count() === 1) {
                $lines[$gameId] = ['home_line' => -(float) $distinct->first(), 'snapshot_id' => $valid->first()->id];
            }
        }

        return $lines;
    }

    private function targetMarket(Game $game): ?array
    {
        $kickoff = $game->sportEvent?->starts_at;
        if (! $kickoff && $game->game_date && $game->game_time) {
            $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->getRawOriginal('game_date'), $game->game_time);
        }
        if ($kickoff) {
            $storageKickoff = $kickoff->copy()->setTimezone((string) config('app.timezone', 'UTC'));
            // Only an observed quote strictly before both the actual and quoted
            // kickoff. Do not use mutable odds_data, live or postgame markets.
            $snapshot = GameOddsSnapshot::query()->where('sport', 'nfl')->where('game_table', 'nfl_games')
                ->where('game_id', $game->id)->where('source', 'odds_api')
                ->where('captured_at', '<', $storageKickoff)->where('captured_at', '<=', now())
                ->whereColumn('captured_at', '<', 'commence_time')->where('commence_time', $storageKickoff)
                ->when($game->odds_api_event_id, fn ($query, $id) => $query->where('odds_api_event_id', $id))
                ->orderByDesc('captured_at')->orderByDesc('id')->first();
            if ($snapshot) {
                $payload = $snapshot->odds_data ?? [];
                $home = $payload['home_team'] ?? null;
                $away = $payload['away_team'] ?? null;
                $homeNames = array_filter([$game->homeTeam?->display_name, $game->homeTeam?->name, $game->homeTeam?->abbreviation,
                    trim(($game->homeTeam?->location ?? '').' '.($game->homeTeam?->name ?? ''))]);
                $awayNames = array_filter([$game->awayTeam?->display_name, $game->awayTeam?->name, $game->awayTeam?->abbreviation,
                    trim(($game->awayTeam?->location ?? '').' '.($game->awayTeam?->name ?? ''))]);
                if (! in_array($home, $homeNames, true) || ! in_array($away, $awayNames, true) || $home === $away) {
                    return null;
                }
                $book = collect($payload['bookmakers'] ?? [])->firstWhere('key', $snapshot->bookmaker_key);
                $markets = collect($book['markets'] ?? [])->where('key', 'spreads');
                $outcomes = collect($markets->first()['outcomes'] ?? []);
                $homeOutcomes = $outcomes->where('name', $home);
                $awayOutcomes = $outcomes->where('name', $away);
                $point = $homeOutcomes->first()['point'] ?? null;
                $opposite = $awayOutcomes->first()['point'] ?? null;
                if ($markets->count() === 1 && $outcomes->count() === 2 && $homeOutcomes->count() === 1 && $awayOutcomes->count() === 1
                    && $this->validLine($point) && $this->validLine($opposite) && abs($point + $opposite) < 0.00001) {
                    return ['home_line' => (float) $point, 'source' => 'observed_pregame',
                        'bookmaker' => $snapshot->bookmaker_title ?? $snapshot->bookmaker_key,
                        'snapshot_id' => $snapshot->id, 'observed_at' => $snapshot->captured_at->toIso8601String()];
                }

                return null;
            }
        }
        // A final archive matchup may be explored retrospectively, explicitly
        // labeled as an archive rather than a contemporaneously observed quote.
        $archive = $game->status === 'STATUS_FINAL' ? ($this->archiveLines([$game->id])[$game->id] ?? null) : null;

        return $archive ? [...$archive, 'source' => 'historical_closing_archive', 'bookmaker' => 'nflverse_closing', 'observed_at' => null] : null;
    }

    private function validLine(mixed $line): bool
    {
        return is_numeric($line) && is_finite((float) $line) && abs((float) $line) <= 60
            && abs((float) $line * 2 - round((float) $line * 2)) < 0.00001;
    }

    private function identity(?string $value): ?string
    {
        $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', $value ?? '')));

        return $value === '' ? null : $value;
    }

    private function record(string $id, string $label, Collection $rows, ?string $reason): array
    {
        $rows = $reason ? collect() : $rows;
        $ats = ['wins' => 0, 'losses' => 0, 'pushes' => 0];
        $su = ['wins' => 0, 'losses' => 0, 'ties' => 0];
        foreach ($rows as $row) {
            $cover = $row['margin'] + $row['line'];
            $ats[$cover > 0 ? 'wins' : ($cover < 0 ? 'losses' : 'pushes')]++;
            $su[$row['margin'] > 0 ? 'wins' : ($row['margin'] < 0 ? 'losses' : 'ties')]++;
        }

        return ['id' => $id, 'label' => $label, 'status' => $reason ? 'unavailable' : ($rows->isEmpty() ? 'no_sample' : 'available'),
            'reason' => $reason, 'sample_size' => $rows->count(), 'unique_games' => $rows->pluck('game_id')->unique()->count(),
            'small_sample' => $rows->count() > 0 && $rows->count() < 20,
            'ats' => $ats, 'outright' => $su,
            'ats_win_pct' => $ats['wins'] + $ats['losses'] > 0 ? round(100 * $ats['wins'] / ($ats['wins'] + $ats['losses']), 1) : null,
            'average_margin' => $rows->isEmpty() ? null : round($rows->avg('margin'), 2),
            'average_cover_margin' => $rows->isEmpty() ? null : round($rows->avg(fn ($row) => $row['margin'] + $row['line']), 2),
            'from_date' => $rows->min('date'), 'through_date' => $rows->max('date'),
            'game_ids' => $rows->pluck('game_id')->unique()->values()->all(),
            'snapshot_ids' => $rows->pluck('snapshot_id')->unique()->values()->all()];
    }
}
