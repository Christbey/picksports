<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\MLB\Concerns\ParsesMlbStatValues;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbBoxscoreTeams;
use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;

class SyncPlayerStats
{
    use ParsesMlbStatValues;
    use ResolvesMlbBoxscoreTeams;

    public function execute(array $gameData, Game $game): int
    {
        $playerSections = $this->boxscoreSection($gameData, 'players');
        if ($playerSections === []) {
            return 0;
        }

        // Delete existing stats for this game to avoid duplicates
        PlayerStat::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($playerSections as $teamData) {
            $team = $this->resolveTeamFromBoxscore($teamData);
            if (! $team) {
                continue;
            }

            // Baseball has multiple statistics sections (batting, pitching, fielding)
            if (! isset($teamData['statistics'])) {
                continue;
            }

            foreach ($teamData['statistics'] as $statSection) {
                $statType = strtolower($statSection['type'] ?? 'batting');

                if (! isset($statSection['athletes'])) {
                    continue;
                }

                foreach ($statSection['athletes'] as $athleteData) {
                    $playerEspnId = $athleteData['athlete']['id'] ?? null;

                    if (! $playerEspnId) {
                        continue;
                    }

                    $player = Player::query()->where('espn_id', $playerEspnId)->first();

                    if (! $player) {
                        continue;
                    }

                    $stats = $athleteData['stats'] ?? [];
                    $labels = $statSection['labels'] ?? $statSection['names'] ?? [];

                    // Parse stats based on type
                    $statData = match ($statType) {
                        'batting' => $this->parseBattingStats($stats, $labels),
                        'pitching' => $this->parsePitchingStats($stats, $labels),
                        'fielding' => $this->parseFieldingStats($stats, $labels),
                        default => [],
                    };

                    if (empty($statData)) {
                        continue;
                    }

                    PlayerStat::create([
                        'player_id' => $player->id,
                        'game_id' => $game->id,
                        'team_id' => $team->id,
                        'stat_type' => $statType,
                        ...$statData,
                    ]);

                    $synced++;
                }
            }
        }

        return $synced;
    }

    protected function parseBattingStats(array $stats, array $labels = []): array
    {
        $mapped = $this->statsByLabel($labels, $stats);
        $hasCombinedHitsAtBats = $this->isCombinedHitsAtBats($stats[0] ?? null);
        $fallbackOffset = $hasCombinedHitsAtBats ? 1 : 0;
        $combined = $this->parseHitsAtBats($this->mappedStat($mapped, ['hab', 'hitsatbats']) ?? ($stats[0] ?? null));

        $battingAverage = $this->toFloat($this->mappedStat($mapped, ['avg', 'battingaverage']));
        $onBasePercentage = $this->toFloat($this->mappedStat($mapped, ['obp', 'onbasepercentage']));
        $sluggingPercentage = $this->toFloat($this->mappedStat($mapped, ['slg', 'sluggingpercentage']));

        return [
            'at_bats' => $this->toInt($this->mappedStat($mapped, ['ab', 'atbats']))
                ?? $combined['at_bats']
                ?? $this->intAt($stats, 0 + $fallbackOffset),
            'runs' => $this->toInt($this->mappedStat($mapped, ['r', 'runs'])) ?? $this->intAt($stats, 1 + $fallbackOffset),
            'hits' => $this->toInt($this->mappedStat($mapped, ['h', 'hits']))
                ?? $combined['hits']
                ?? $this->intAt($stats, 2 + $fallbackOffset),
            'doubles' => $this->toInt($this->mappedStat($mapped, ['2b', 'doubles'])),
            'triples' => $this->toInt($this->mappedStat($mapped, ['3b', 'triples'])),
            'home_runs' => $this->toInt($this->mappedStat($mapped, ['hr', 'homeruns'])) ?? $this->intAt($stats, 4 + $fallbackOffset),
            'rbis' => $this->toInt($this->mappedStat($mapped, ['rbi', 'rbis'])) ?? $this->intAt($stats, 3 + $fallbackOffset),
            'walks' => $this->toInt($this->mappedStat($mapped, ['bb', 'walks'])) ?? $this->intAt($stats, 5 + $fallbackOffset),
            'strikeouts' => $this->toInt($this->mappedStat($mapped, ['k', 'so', 'strikeouts'])) ?? $this->intAt($stats, 6 + $fallbackOffset),
            'stolen_bases' => $this->toInt($this->mappedStat($mapped, ['sb', 'stolenbases'])) ?? $this->intAt($stats, 7 + $fallbackOffset),
            'caught_stealing' => $this->toInt($this->mappedStat($mapped, ['cs', 'caughtstealing'])),
            'batting_average' => $this->sanitizeRate($battingAverage),
            'on_base_percentage' => $this->sanitizeRate($onBasePercentage),
            'slugging_percentage' => $this->sanitizeRate($sluggingPercentage, 5.0),
        ];
    }

    protected function parsePitchingStats(array $stats, array $labels = []): array
    {
        $mapped = $this->statsByLabel($labels, $stats);

        return [
            'innings_pitched' => $this->normalizeInningsPitched(
                $this->mappedStat($mapped, ['ip', 'inningspitched']) ?? ($stats[0] ?? null)
            ),
            'hits_allowed' => $this->toInt($this->mappedStat($mapped, ['h', 'hits'])) ?? $this->intAt($stats, 1),
            'runs_allowed' => $this->toInt($this->mappedStat($mapped, ['r', 'runs'])) ?? $this->intAt($stats, 2),
            'earned_runs' => $this->toInt($this->mappedStat($mapped, ['er', 'earnedruns'])) ?? $this->intAt($stats, 3),
            'walks_allowed' => $this->toInt($this->mappedStat($mapped, ['bb', 'walks'])) ?? $this->intAt($stats, 4),
            'strikeouts_pitched' => $this->toInt($this->mappedStat($mapped, ['k', 'so', 'strikeouts'])) ?? $this->intAt($stats, 5),
            'home_runs_allowed' => $this->toInt($this->mappedStat($mapped, ['hr', 'homeruns'])) ?? $this->intAt($stats, 6),
            'era' => $this->toFloat($this->mappedStat($mapped, ['era'])) ?? $this->floatAt($stats, 7),
            'pitches_thrown' => $this->toInt($this->mappedStat($mapped, ['pitches', 'pit', 'p', 'pitchcount', 'pc'])) ?? $this->intAt($stats, 8),
            'pitch_count' => $this->toInt($this->mappedStat($mapped, ['pitchcount', 'pc', 'pitches', 'pit', 'p'])) ?? $this->intAt($stats, 8),
        ];
    }

    protected function parseFieldingStats(array $stats, array $labels = []): array
    {
        $mapped = $this->statsByLabel($labels, $stats);

        return [
            'putouts' => $this->toInt($this->mappedStat($mapped, ['po', 'putouts'])) ?? $this->intAt($stats, 0),
            'assists' => $this->toInt($this->mappedStat($mapped, ['a', 'assists'])) ?? $this->intAt($stats, 1),
            'errors' => $this->toInt($this->mappedStat($mapped, ['e', 'errors'])) ?? $this->intAt($stats, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statsByLabel(array $labels, array $stats): array
    {
        $mapped = [];

        foreach ($labels as $index => $label) {
            if (! array_key_exists($index, $stats)) {
                continue;
            }

            $normalized = $this->normalizeLabel((string) $label);
            if ($normalized === '') {
                continue;
            }

            $mapped[$normalized] = $stats[$index];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<int, string>  $candidates
     */
    private function mappedStat(array $mapped, array $candidates): mixed
    {
        foreach ($candidates as $candidate) {
            $key = $this->normalizeLabel($candidate);
            if ($key !== '' && array_key_exists($key, $mapped)) {
                return $mapped[$key];
            }
        }

        return null;
    }

    private function normalizeLabel(string $label): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $label));
    }

    private function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function toFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function isCombinedHitsAtBats(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+-\d+$/', trim($value)) === 1;
    }

    /**
     * @return array{hits: ?int, at_bats: ?int}
     */
    private function parseHitsAtBats(mixed $value): array
    {
        if (! is_string($value)) {
            return ['hits' => null, 'at_bats' => null];
        }

        if (! preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $value, $matches)) {
            return ['hits' => null, 'at_bats' => null];
        }

        return [
            'hits' => (int) $matches[1],
            'at_bats' => (int) $matches[2],
        ];
    }

    private function sanitizeRate(?float $value, float $max = 1.0): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0 || $value > $max) {
            return null;
        }

        return $value;
    }

    private function normalizeInningsPitched(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $whole = (int) floor($number);
            $fractionDigit = (int) round(($number - $whole) * 10);

            if ($fractionDigit === 1 || $fractionDigit === 2) {
                return $whole + ($fractionDigit / 3);
            }

            return $number;
        }

        $text = trim((string) $value);
        if (! preg_match('/^(\d+)(?:\.(\d))?$/', $text, $matches)) {
            return null;
        }

        $whole = (int) $matches[1];
        $fractionDigit = isset($matches[2]) ? (int) $matches[2] : 0;

        if ($fractionDigit === 1 || $fractionDigit === 2) {
            return $whole + ($fractionDigit / 3);
        }

        if ($fractionDigit === 0) {
            return (float) $whole;
        }

        return (float) $text;
    }
}
