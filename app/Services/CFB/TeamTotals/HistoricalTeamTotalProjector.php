<?php

namespace App\Services\CFB\TeamTotals;

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Support\CfbSeasonAffiliationResolver;

/** Independent score model. Never reads or writes saved game predictions. */
class HistoricalTeamTotalProjector
{
    private array $fits = [];

    private array $affiliations = [];

    public function project(Game $game): array
    {
        $date = $game->game_date->format('Y-m-d');
        $fit = $this->fits[$game->season.':'.$date] ??= $this->fit((int) $game->season, $date);
        $result = ['model' => 'cfb-historical-team-total-v1', 'as_of_exclusive' => $date,
            'status' => 'experimental', 'home_points' => null, 'away_points' => null,
            'league_points' => round($fit['mean'], 2), 'home_advantage_points' => round($fit['venue'], 2),
            'history_games' => $fit['games'], 'history_seasons' => $fit['seasons'],
            'limitations' => ['No calibrated Over probability.', 'Quarterback, injury, coaching changes and pace are not adjusted.', 'Historical records may contain later provider corrections.'],
            'teams' => []];
        foreach (['home', 'away'] as $side) {
            $id = $game->{$side.'_team_id'};
            $opponent = $game->{($side === 'home' ? 'away' : 'home').'_team_id'};
            $sample = $fit['samples'][$id] ?? ['games' => 0, 'by_season' => [], 'weight' => 0];
            $result['teams'][$side] = [...$sample, 'weight' => round($sample['weight'], 2),
                'offense_adjustment' => round($fit['offense'][$id] ?? 0, 2),
                'opponent_defense_adjustment' => round($fit['defense'][$opponent] ?? 0, 2)];
            if (! $this->fbs($id, (int) $game->season)) {
                $result['status'] = 'unsupported_subdivision';
            } elseif ($sample['games'] < 6 || $sample['weight'] < 2 || $fit['games'] < 30) {
                if ($result['status'] !== 'unsupported_subdivision') {
                    $result['status'] = 'insufficient_history';
                }
            }
            $venue = $game->neutral_site ? 0 : ($side === 'home' ? 1 : -1) * $fit['venue'];
            $result[$side.'_points'] = round(max(0, $fit['mean'] + ($fit['offense'][$id] ?? 0) + ($fit['defense'][$opponent] ?? 0) + $venue), 2);
        }
        if ($result['status'] !== 'experimental') {
            $result['home_points'] = $result['away_points'] = null;
        }

        return $result;
    }

    private function fbs(int $id, int $season): bool
    {
        $key = $id.':'.$season;
        if (! array_key_exists($key, $this->affiliations)) {
            $stored = TeamSeasonAffiliation::where('team_id', $id)->where('season', $season)->first();
            $team = Team::find($id);
            $this->affiliations[$key] = $stored ? $stored->isFbs() : ($team && app(CfbSeasonAffiliationResolver::class)->attributesForSeason($team, $season)['subdivision'] === 'FBS');
        }

        return $this->affiliations[$key];
    }

    private function fit(int $season, string $date): array
    {
        // Exclude the entire target day: no same-day final or target outcome can leak into a replay.
        $games = Game::whereBetween('season', [max(2023, $season - 2), $season])->whereDate('game_date', '<', $date)
            ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->orderBy('game_date')->orderBy('id')->get();
        $rows = [];
        $samples = [];
        $seasons = [];
        $total = 0;
        $weight = 0;
        $margin = 0;
        $venueWeight = 0;
        foreach ($games as $g) {
            if (! $this->fbs($g->home_team_id, $g->season) || ! $this->fbs($g->away_team_id, $g->season)) {
                continue;
            }
            $w = [1, .65, .35][$season - $g->season];
            $seasons[$g->season] = ($seasons[$g->season] ?? 0) + 1;
            foreach (['home', 'away'] as $side) {
                $id = $g->{$side.'_team_id'};
                $opp = $g->{($side === 'home' ? 'away' : 'home').'_team_id'};
                $score = (float) $g->{$side.'_score'};
                $sign = $g->neutral_site ? 0 : ($side === 'home' ? 1 : -1);
                $rows[] = ['id' => $id, 'opp' => $opp, 'score' => $score, 'w' => $w, 'sign' => $sign];
                $samples[$id] ??= ['games' => 0, 'weight' => 0, 'by_season' => []];
                $samples[$id]['games']++;
                $samples[$id]['weight'] += $w;
                $samples[$id]['by_season'][$g->season] = ($samples[$id]['by_season'][$g->season] ?? 0) + 1;
                $total += $score * $w;
                $weight += $w;
            }
            if (! $g->neutral_site) {
                $margin += ($g->home_score - $g->away_score) * $w;
                $venueWeight += $w;
            }
        }
        $mean = $weight > 0 ? $total / $weight : 26;
        $venue = max(-3, min(3, $margin / max(1, $venueWeight) / 2));
        $offense = [];
        $defense = [];
        // Alternating ridge estimates separate offense from the defenses it faced.
        // Eight weighted games of shrinkage keep small samples near the league mean.
        for ($iteration = 0; $iteration < 12; $iteration++) {
            $o = [];
            $d = [];
            foreach ($rows as $r) {
                $o[$r['id']] = ($o[$r['id']] ?? 0) + $r['w'] * ($r['score'] - $mean - $r['sign'] * $venue - ($defense[$r['opp']] ?? 0));
            }
            foreach ($o as $id => $sum) {
                $offense[$id] = $sum / (8 + $samples[$id]['weight']);
            }
            foreach ($rows as $r) {
                $d[$r['opp']] = ($d[$r['opp']] ?? 0) + $r['w'] * ($r['score'] - $mean - $r['sign'] * $venue - ($offense[$r['id']] ?? 0));
            }
            foreach ($d as $id => $sum) {
                $defense[$id] = $sum / (8 + $samples[$id]['weight']);
            }
        }

        return compact('mean', 'venue', 'offense', 'defense', 'samples', 'seasons') + ['games' => count($rows) / 2];
    }
}
