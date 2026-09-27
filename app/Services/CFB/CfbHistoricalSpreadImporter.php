<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Retrospective provider lines, never backdated executable pregame quotes. */
class CfbHistoricalSpreadImporter
{
    public const SOURCE = 'cfbd_historical';

    public function import(array $rows, int $season, bool $dryRun = false, ?int $sourceFileId = null): array
    {
        $identityColumns = array_values(array_intersect(['espn_id', 'espn_event_id'], Schema::getColumnListing('cfb_games')));
        if ($identityColumns === []) {
            throw new \RuntimeException('CFB game provider identity columns are unavailable.');
        }
        $report = ['season' => $season, 'rows' => count($rows), 'matched_games' => 0, 'snapshots_created' => 0,
            'quotes_created' => 0, 'existing' => 0, 'eligible_lines' => 0, 'skipped' => []];
        foreach ($rows as $row) {
            $reason = $this->validate($row, $season);
            if ($reason !== null) {
                $report['skipped'][] = ['provider_game_id' => $row['id'] ?? null, 'reason' => $reason];

                continue;
            }
            $games = Game::with(['homeTeam', 'awayTeam'])->where('season', $season)
                ->where(function ($q) use ($identityColumns, $row) {
                    foreach ($identityColumns as $column) {
                        $q->orWhere($column, (string) $row['id']);
                    }
                })->get();
            $game = $games->count() === 1 ? $games->first() : null;
            if (! $game || ! $this->matches($game, $row)) {
                $report['skipped'][] = ['provider_game_id' => $row['id'], 'reason' => $game ? 'identity_or_result_conflict' : 'unmatched_or_ambiguous_game'];

                continue;
            }
            $report['matched_games']++;
            foreach ($row['lines'] ?? [] as $line) {
                if (! is_numeric($line['spread'] ?? null) || ! is_finite((float) $line['spread'])
                    || abs((float) $line['spread']) > 150 || empty($line['provider'])) {
                    $report['skipped'][] = ['provider_game_id' => $row['id'], 'reason' => 'missing_or_invalid_spread'];

                    continue;
                }
                $report['eligible_lines']++;
                if ($dryRun) {
                    continue;
                }
                $created = $this->store($game, $row, $line, $sourceFileId);
                $report[$created ? 'snapshots_created' : 'existing']++;
                $report['quotes_created'] += $created ? 2 : 0;
            }
        }

        return $report;
    }

    private function validate(array $row, int $season): ?string
    {
        if (empty($row['id']) || (int) ($row['season'] ?? 0) !== $season) {
            return 'invalid_identity_or_season';
        }
        try {
            if (empty($row['startDate']) || CarbonImmutable::parse($row['startDate'])->gte(now())) {
                return 'not_historical';
            }
        } catch (\Throwable) {
            return 'invalid_kickoff';
        }
        if (! is_numeric($row['homeScore'] ?? null) || ! is_numeric($row['awayScore'] ?? null)) {
            return 'missing_final_scores';
        }

        return null;
    }

    private function matches(Game $game, array $row): bool
    {
        if (! in_array($game->status, ['STATUS_FINAL', 'STATUS_FINAL_OVERTIME'], true)
            || (int) $game->home_score !== (int) $row['homeScore']
            || (int) $game->away_score !== (int) $row['awayScore']) {
            return false;
        }
        foreach (['home', 'away'] as $side) {
            $team = $game->{$side.'Team'};
            if (! $team || ! in_array((string) ($row[$side.'TeamId'] ?? ''),
                array_filter([(string) $team->espn_id, (string) $team->cfbd_team_id]), true)) {
                return false;
            }
        }

        return abs($game->game_date->diffInDays(CarbonImmutable::parse($row['startDate']), false)) <= 1;
    }

    private function store(Game $game, array $row, array $line, ?int $sourceFileId): bool
    {
        $book = Str::slug($line['provider'], '_');
        $spread = (float) $line['spread'];
        // Source revisions remain separate; retries of the same evidence do not duplicate it.
        $payload = ['provider_game_id' => $row['id'], 'season' => $row['season'], 'startDate' => $row['startDate'],
            'homeTeamId' => $row['homeTeamId'], 'awayTeamId' => $row['awayTeamId'], 'line' => $line];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($game, $row, $line, $sourceFileId, $book, $spread, $payload, $hash) {
            Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            if (GameOddsSnapshot::where('sport', 'cfb')->where('game_table', 'cfb_games')->where('game_id', $game->id)
                ->where('source', self::SOURCE)->where('payload_hash', $hash)->exists()) {
                return false;
            }
            $captured = now();
            $kickoff = CarbonImmutable::parse($row['startDate'])->setTimezone(config('app.timezone'));
            $metadata = ['evidence_type' => 'retrospective_provider_line', 'line_type' => 'provider_historical',
                'provider' => 'cfbd', 'provider_game_id' => $row['id'], 'provider_source_file_id' => $sourceFileId,
                'source_endpoint' => '/lines', 'source_season' => $row['season'], 'source_season_type' => $row['seasonType'] ?? null,
                'home_conference' => $row['homeConference'] ?? null, 'away_conference' => $row['awayConference'] ?? null,
                'opening_home_spread' => $line['spreadOpen'] ?? null, 'provider_observed_at' => null,
                'historical_availability_proven' => false, 'closing_line_verified' => false,
                'retrieved_at' => $captured->toIso8601String(), 'spread_convention' => 'home_handicap_negative_home_favored'];
            $snapshot = GameOddsSnapshot::create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
                'source' => self::SOURCE, 'bookmaker_key' => $book, 'bookmaker_title' => $line['provider'],
                'commence_time' => $kickoff, 'captured_at' => $captured, 'payload_hash' => $hash,
                'odds_data' => $payload, 'market_context' => $metadata]);
            foreach (['home' => $spread, 'away' => -$spread] as $side => $handicap) {
                MarketQuote::create(['game_odds_snapshot_id' => $snapshot->id, 'sport' => 'cfb', 'game_table' => 'cfb_games',
                    'game_id' => $game->id, 'source' => self::SOURCE, 'bookmaker_key' => $book, 'bookmaker_title' => $line['provider'],
                    'market_key' => 'spreads', 'side' => $side, 'line' => $handicap, 'price' => null,
                    'bookmaker_home_line' => $spread, 'home_margin_equivalent' => -$spread,
                    'commence_time' => $kickoff, 'captured_at' => $captured, 'is_pregame' => false,
                    'quote_hash' => hash('sha256', self::SOURCE.'|'.$game->id.'|'.$hash.'|'.$side), 'metadata' => $metadata]);
            }

            return true;
        });
    }
}
