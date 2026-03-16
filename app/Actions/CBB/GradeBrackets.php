<?php

namespace App\Actions\CBB;

use App\Models\CbbBracket;
use App\Models\CBB\Game;
use App\Support\CbbBracketTree;
use Illuminate\Support\Collection;

class GradeBrackets
{
    public function __construct(
        private readonly CbbBracketTree $bracketTree,
    ) {}

    public function execute(int $season, ?CbbBracket $onlyBracket = null): int
    {
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where(function ($query) {
                $query->where('is_ncaa_tournament', true)
                    ->orWhere('tournament_note', 'like', "NCAA Men's Basketball Championship%");
            })
            ->get();

        $brackets = $onlyBracket
            ? collect([$onlyBracket])
            : CbbBracket::query()->where('season', $season)->get();

        foreach ($brackets as $bracket) {
            $tree = $this->bracketTree->build($games, $bracket->picks ?? [], $season);
            $graded = $this->gradeTree($tree, $bracket->picks ?? []);

            $bracket->forceFill($graded)->save();
        }

        return $brackets->count();
    }

    private function gradeTree(array $tree, array $picks): array
    {
        $results = [];
        $pointsEarned = 0;
        $maxPointsRemaining = 0;
        $correctPicks = 0;
        $incorrectPicks = 0;
        $gradedThroughRound = null;

        $matchups = collect($tree['regions'])
            ->flatMap(fn (array $region) => collect($region['rounds'])->flatMap(fn (array $round) => $round['matchups']))
            ->merge($tree['standalone']['final_four'])
            ->push($tree['standalone']['national_championship']);

        foreach ($matchups as $matchup) {
            $roundKey = $matchup['roundKey'];
            $roundPoints = (int) (config("cbb_bracket.scoring.{$roundKey}") ?? 0);
            $pickedId = $picks[$matchup['id']] ?? null;
            $winnerId = $this->winningParticipantId($matchup);

            if ($winnerId === null) {
                $status = $pickedId ? 'pending' : 'unpicked';
                if ($pickedId) {
                    $maxPointsRemaining += $roundPoints;
                }
            } elseif ($pickedId === null) {
                $status = 'unpicked';
                $gradedThroughRound = $roundKey;
            } elseif ($pickedId === $winnerId) {
                $status = 'correct';
                $pointsEarned += $roundPoints;
                $correctPicks++;
                $gradedThroughRound = $roundKey;
            } else {
                $status = 'incorrect';
                $incorrectPicks++;
                $gradedThroughRound = $roundKey;
            }

            $results[$matchup['id']] = [
                'status' => $status,
                'round_key' => $roundKey,
                'points' => $status === 'correct' ? $roundPoints : 0,
                'possible_points' => $roundPoints,
                'picked_id' => $pickedId,
                'winning_id' => $winnerId,
            ];
        }

        return [
            'results' => $results,
            'points_earned' => $pointsEarned,
            'max_points_remaining' => $maxPointsRemaining,
            'correct_picks' => $correctPicks,
            'incorrect_picks' => $incorrectPicks,
            'graded_through_round' => $gradedThroughRound,
        ];
    }

    private function winningParticipantId(array $matchup): ?string
    {
        $game = $matchup['game'] ?? null;
        if (! is_array($game)) {
            return null;
        }

        if (($game['status'] ?? null) !== config('cbb.statuses.final')) {
            return null;
        }

        $homeScore = $game['homeScore'] ?? null;
        $awayScore = $game['awayScore'] ?? null;

        if (! is_numeric($homeScore) || ! is_numeric($awayScore) || $homeScore === $awayScore) {
            return null;
        }

        $winnerTeamId = $homeScore > $awayScore
            ? ($game['homeTeam']['id'] ?? null)
            : ($game['awayTeam']['id'] ?? null);

        return $winnerTeamId ? "team:{$winnerTeamId}" : null;
    }
}
