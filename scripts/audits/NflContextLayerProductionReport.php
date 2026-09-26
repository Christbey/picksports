<?php

namespace Audit;

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamCoachSeason;
use App\Services\NFL\NflHistoricalMarketEvidence;
use Illuminate\Support\Facades\Http;

/** Explicit read-only audit; run from Tinker after loading its two dependencies. */
final class NflContextLayerProductionReport
{
    public static function run(int $targetSeason, int $targetWeek, ?array $targetIds = null): array
    {
        $sourceUrl = 'https://raw.githubusercontent.com/nflverse/nfldata/master/data/games.csv';
        $csv = Http::timeout(15)->get($sourceUrl)->throw()->body();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $source = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if (count($row) === count($header)) {
                $row = array_combine($header, $row);
                $source[$row['game_id']] = $row;
            }
        }
        fclose($stream);
        $normalize = fn ($team) => match ($team) {
            'OAK' => 'LV', 'SD' => 'LAC', 'STL', 'LA' => 'LAR', 'WAS' => 'WSH', default => $team,
        };
        $teams = Team::query()->pluck('abbreviation', 'id')->map($normalize)->all();
        $games = Game::query()->whereBetween('season', [2009, $targetSeason - 1])->where('season_type', '2')
            ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->get(['id', 'nflverse_game_id', 'season', 'game_date', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site', 'home_coach', 'away_coach', 'home_qb_id', 'away_qb_id', 'home_qb_name', 'away_qb_name']);
        $qbIdsByName = [];
        foreach ($games as $historical) {
            foreach (['home', 'away'] as $side) {
                $name = mb_strtolower(trim($historical->{$side.'_qb_name'} ?? ''));
                $id = $historical->{$side.'_qb_id'};
                if ($name !== '' && $id !== null && $id !== '') {
                    $qbIdsByName[$name][$id] = true;
                }
            }
        }
        $evidence = app(NflHistoricalMarketEvidence::class);
        $lines = (new \ReflectionMethod($evidence, 'archiveLines'))->invoke($evidence, $games->modelKeys());
        $inputs = [];
        $timeCoverage = ['verified' => 0, 'missing_or_unmatched_source_time' => 0];
        foreach ($games as $game) {
            $home = $teams[$game->home_team_id];
            $away = $teams[$game->away_team_id];
            $original = $source[$game->nflverse_game_id] ?? null;
            $kickoff = null;
            if ($original && $normalize($original['home_team']) === $home && $normalize($original['away_team']) === $away
                && (int) $original['season'] === (int) $game->season && $original['game_type'] === 'REG'
                && is_numeric($original['home_score']) && is_numeric($original['away_score'])
                && (int) $original['home_score'] === (int) $game->home_score && (int) $original['away_score'] === (int) $game->away_score
                && preg_match('/^\d{2}:\d{2}$/', $original['gametime'])) {
                $kickoff = (new \DateTimeImmutable($original['gameday'].' '.$original['gametime'], new \DateTimeZone('America/New_York')))->format(DATE_ATOM);
            }
            $timeCoverage[$kickoff === null ? 'missing_or_unmatched_source_time' : 'verified']++;
            $inputs[] = ['id' => $game->id, 'date' => $game->game_date->toDateString(), 'season' => (int) $game->season,
                'home' => $home, 'away' => $away, 'home_score' => $game->home_score, 'away_score' => $game->away_score,
                'home_line' => $lines[$game->id]['home_line'] ?? null,
                'neutral' => $game->neutral_site === null ? null : (bool) $game->neutral_site,
                'home_coach' => $game->home_coach, 'away_coach' => $game->away_coach,
                'home_qb_id' => $game->home_qb_id, 'away_qb_id' => $game->away_qb_id, 'kickoff' => $kickoff];
        }
        $postseason = Game::query()->whereBetween('season', [2009, $targetSeason - 1])->where('season_type', '3')
            ->where('status', 'STATUS_FINAL')->get(['id', 'season', 'home_team_id', 'away_team_id'])
            ->map(fn ($g) => ['id' => $g->id, 'season' => (int) $g->season, 'home' => $teams[$g->home_team_id], 'away' => $teams[$g->away_team_id]])->all();
        $fields = NflContextLayerAudit::playoffFields($postseason);
        $observations = NflContextLayerAudit::observations($inputs, $fields);
        $coaches = TeamCoachSeason::query()->with('coach')->where('season', $targetSeason)->get()->keyBy('team_id');
        $targets = Game::query()->with(['homeTeam', 'awayTeam', 'sportEvent', 'prediction'])->where('season', $targetSeason)->where('season_type', '2')->where('week', $targetWeek)
            ->when($targetIds !== null, fn ($q) => $q->whereKey($targetIds))->get();
        $out = [];
        foreach ($targets as $game) {
            $market = (new \ReflectionMethod($evidence, 'targetMarket'))->invoke($evidence, $game);
            $kickoff = $game->sportEvent?->starts_at?->toIso8601String();
            $window = NflContextLayerAudit::timeWindow($kickoff);
            $sides = [];
            foreach (['away', 'home'] as $side) {
                $opposite = $side === 'home' ? 'away' : 'home';
                $team = $teams[$game->{$side.'_team_id'}];
                $opponent = $teams[$game->{$opposite.'_team_id'}];
                $line = $market === null ? null : ($side === 'home' ? 1 : -1) * $market['home_line'];
                $venue = $game->neutral_site === null ? null : ($game->neutral_site ? 'neutral' : $side);
                $coach = $coaches->get($game->{$side.'_team_id'})?->coach?->display_name;
                $qbContext = $game->prediction?->model_metadata['qb_form'][$side] ?? [];
                $qbName = $game->{$side.'_qb_name'} ?? $qbContext['qb_name'] ?? null;
                // Prediction QB IDs may be local player IDs: never compare them to GSIS IDs.
                $candidateIds = array_keys($qbIdsByName[mb_strtolower(trim($qbName ?? ''))] ?? []);
                $qbId = $game->{$side.'_qb_id'} ?? (count($candidateIds) === 1 ? $candidateIds[0] : null);
                $qbSource = $game->{$side.'_qb_id'} !== null ? 'game_record' : ($qbId !== null ? 'projected_name_unique_historical_gsis_match' : 'unresolved');
                $layers = [];
                if ($window !== null) {
                    $layers['kickoff'] = NflContextLayerAudit::layer($observations, 'time_window', $window, $team, $line, $venue, $coach);
                }
                if (isset($fields[$targetSeason - 1])) {
                    $layers['previous_playoff'] = NflContextLayerAudit::layer($observations, 'vs_previous_playoff', in_array($opponent, $fields[$targetSeason - 1], true), $team, $line, $venue, $coach);
                }
                $defenseHistory = [];
                foreach (['vs_top_5_scoring_defense', 'vs_top_10_scoring_defense', 'vs_bottom_5_scoring_defense', 'vs_bottom_10_scoring_defense'] as $field) {
                    $defenseHistory[$field] = NflContextLayerAudit::layer($observations, $field, true, $team, $line, $venue, $coach);
                }
                $sides[$side] = ['team' => $team, 'opponent' => $opponent, 'line' => $line, 'venue' => $venue, 'coach' => $coach, 'layers' => $layers,
                    'quarterback' => ['name' => $qbName, 'gsis_id' => $qbId, 'identity_source' => $qbSource, 'projected' => $game->{$side.'_qb_id'} === null],
                    'identity_records' => NflContextLayerAudit::identities($observations, $team, $coach, $qbId, $line, $venue, $window),
                    'scoring_defense_history_not_current_matchup_signal' => $defenseHistory];
            }
            $out[] = ['game_id' => $game->id, 'kickoff' => $kickoff, 'window' => $window, 'market' => $market, 'sides' => $sides];
        }
        $coverage = [];
        foreach (['time_window', 'vs_previous_playoff', 'vs_rookie_qb', 'vs_rookie_coach', 'opponent_scoring_defense'] as $field) {
            $coverage[$field] = ['known_team_appearances' => count(array_filter($observations, fn ($r) => $r[$field] !== null)), 'total_team_appearances' => count($observations)];
        }

        return ['generated_at' => now()->toIso8601String(), 'production_modified' => false, 'predictive_weight' => 0,
            'historical_seasons' => [2009, $targetSeason - 1], 'historical_week_filter' => null,
            'source_url' => $sourceUrl, 'source_sha256' => hash('sha256', $csv),
            'games' => count($inputs), 'archived_lines' => count($lines), 'time_coverage' => $timeCoverage,
            'playoff_fields' => $fields, 'coverage' => $coverage, 'targets' => $out,
            'unavailable' => ['rookie_qb' => 'Verified historical career rookie-year mappings not supplied; missing is unknown, not veteran.',
                'rookie_coach' => 'Verified NFL head-coaching debut season mappings not supplied; new-team tenure is not rookie status.',
                'current_defensive_rank' => 'Upcoming Week 3 cannot meet the existing three-game minimum for all 32 teams. Historical scoring-defense rankings are reconstructed, not EPA rankings.'],
        ];
    }
}
