<?php

namespace App\Services\NFL;

class NflPlayoffBracket
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $fields
     * @param  array<string, array{home:int,neutral:bool|null,winner:int|null,conflict:bool}>  $knownGames
     * @param  callable(array, array, bool, bool): array  $simulateGame
     * @return array{conference_champions:array<string,int>,super_bowl_champion:int|null,unmatched_games:array}
     */
    public function resolve(array $fields, array $knownGames, callable $simulateGame): array
    {
        $matched = [];
        $play = function (array $first, array $second, bool $useHomeField, bool $firstIsHome = true) use ($knownGames, $simulateGame, &$matched): array {
            $key = self::matchupKey($first['team_id'], $second['team_id']);
            $game = $knownGames[$key] ?? null;
            if ($game === null || $game['conflict']) {
                return $simulateGame($first, $second, $useHomeField, $firstIsHome);
            }

            $matched[$key] = true;
            if ($game['winner'] !== null) {
                return $game['winner'] === $first['team_id'] ? $first : $second;
            }

            return $simulateGame(
                $first,
                $second,
                $game['neutral'] === null ? $useHomeField : ! $game['neutral'],
                $game['home'] === $first['team_id']
            );
        };

        $champions = [];
        foreach ($fields as $conference => $field) {
            $champion = $this->conferenceChampion($field, $play);
            if ($champion !== null) {
                $champions[$conference] = $champion;
            }
        }

        $superBowlChampion = isset($champions['AFC'], $champions['NFC'])
            ? $play($champions['AFC'], $champions['NFC'], false)['team_id']
            : null;

        return [
            'conference_champions' => array_map(fn (array $team): int => $team['team_id'], $champions),
            'super_bowl_champion' => $superBowlChampion,
            'unmatched_games' => array_diff_key($knownGames, $matched),
        ];
    }

    public static function matchupKey(int $first, int $second): string
    {
        return min($first, $second).':'.max($first, $second);
    }

    /**
     * @param  array<int, array<string,mixed>>  $field
     */
    private function conferenceChampion(array $field, callable $play): ?array
    {
        if ($field === []) {
            return null;
        }

        $bySeed = array_column($field, null, 'seed');

        $wildCardWinners = [];
        foreach ([[2, 7], [3, 6], [4, 5]] as [$homeSeed, $awaySeed]) {
            if (! isset($bySeed[$homeSeed], $bySeed[$awaySeed])) {
                continue;
            }
            $wildCardWinners[] = $play($bySeed[$homeSeed], $bySeed[$awaySeed], true);
        }

        if (! isset($bySeed[1])) {
            return $wildCardWinners !== [] ? $wildCardWinners[0] : null;
        }

        usort($wildCardWinners, fn (array $a, array $b): int => ($b['seed'] <=> $a['seed']));
        $lowestRemaining = array_shift($wildCardWinners);
        if ($lowestRemaining === null) {
            return $bySeed[1];
        }

        $divisionalA = $play($bySeed[1], $lowestRemaining, true);
        $divisionalB = count($wildCardWinners) >= 2
            ? $play($wildCardWinners[0], $wildCardWinners[1], true, $wildCardWinners[0]['seed'] < $wildCardWinners[1]['seed'])
            : ($wildCardWinners[0] ?? null);

        if ($divisionalB === null) {
            return $divisionalA;
        }

        return $play($divisionalA, $divisionalB, true, $divisionalA['seed'] < $divisionalB['seed']);
    }
}
