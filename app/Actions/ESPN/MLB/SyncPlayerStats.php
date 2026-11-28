<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use App\Actions\ESPN\MLB\Concerns\ParsesMlbStatValues;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbBoxscoreTeams;
use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerStat;
use App\Models\MLB\Team;
use App\Support\ESPN\ObservedStatValue;
use DomainException;

class SyncPlayerStats
{
    use ParsesMlbStatValues;
    use ResolvesMlbBoxscoreTeams;
    use ValidatesBoxscoreReplacement;

    public function execute(array $gameData, Game $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game = $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();
            try {
                return $this->replacePlayerStats($gameData, $game);
            } catch (DomainException) {
                return 0;
            }
        });
    }

    protected function replacePlayerStats(array $gameData, Game $game): int
    {
        $playerSections = $this->boxscoreSection($gameData, 'players');
        if ($playerSections === []) {
            return 0;
        }

        $teams = $this->replacementTeams($playerSections, $game, Team::class, PlayerStat::class);
        if ($teams === null) {
            return 0;
        }
        $players = Player::query()->whereIn('espn_id', collect($playerSections)->flatMap(fn ($team) => collect($team['statistics'] ?? [])->flatMap(fn ($section) => collect($section['athletes'] ?? [])->pluck('athlete.id'))))->get()->keyBy('espn_id');
        $rows = [];
        $owners = [];
        $synced = 0;
        foreach ($playerSections as $teamData) {
            $team = $teams->get($teamData['team']['id']);
            if (! is_array($teamData['statistics'] ?? null) || $teamData['statistics'] === []) {
                return 0;
            }
            foreach ($teamData['statistics'] as $section) {
                $type = strtolower((string) ($section['type'] ?? ''));
                if (! is_array($section['athletes'] ?? null)) {
                    return 0;
                }
                foreach ($section['athletes'] as $athlete) {
                    $player = $players->get(data_get($athlete, 'athlete.id'));
                    if (! $player || ! is_array($athlete['stats'] ?? null) || $athlete['stats'] === []) {
                        return 0;
                    }
                    $stats = $athlete['stats'];
                    $labels = $section['labels'] ?? $section['names'] ?? [];
                    $data = match ($type) {
                        'batting' => $this->parseBattingStats($stats, $labels),
                        'pitching' => $this->parsePitchingStats($stats, $labels),
                        'fielding' => $this->parseFieldingStats($stats, $labels),
                        default => [],
                    };
                    if ($data === []) {
                        continue;
                    }
                    if (array_filter($data, fn ($value) => $value !== null) === []) {
                        return 0;
                    }
                    if (isset($owners[$player->id]) && $owners[$player->id] !== $team->id) {
                        return 0;
                    }
                    $owners[$player->id] = $team->id;
                    $key = $player->id.':'.$type;
                    $rows[$key] = $this->mergeObservedValues($rows[$key] ?? [], ['player_id' => $player->id, 'game_id' => $game->id, 'team_id' => $team->id, 'stat_type' => $type, ...$data]);
                    $synced++;
                }
            }
        }
        if ($rows === []) {
            return 0;
        }

        if (! $this->preservesObservedFields(PlayerStat::class, $game, $rows, ['player_id', 'stat_type'])) {
            return 0;
        }
        PlayerStat::query()->where('game_id', $game->id)->delete();
        foreach ($rows as $row) {
            PlayerStat::query()->create($row);
        }

        return $synced;
    }

    protected function parseBattingStats(array $stats, array $labels = []): array
    {
        $mapped = $this->statsByLabel($labels, $stats);
        $combined = $this->parseHitsAtBats($this->mappedStat($mapped, ['hab', 'hitsatbats']));

        foreach (['hits' => ['h', 'hits'], 'at_bats' => ['ab', 'atbats']] as $field => $aliases) {
            $explicit = $this->toInt($this->mappedStat($mapped, $aliases));
            if ($explicit !== null && $combined[$field] !== null && $explicit !== $combined[$field]) {
                throw new DomainException('Conflicting combined batting observation.');
            }
        }

        $battingAverage = $this->toFloat($this->mappedStat($mapped, ['avg', 'battingaverage']));
        $onBasePercentage = $this->toFloat($this->mappedStat($mapped, ['obp', 'onbasepercentage']));
        $sluggingPercentage = $this->toFloat($this->mappedStat($mapped, ['slg', 'sluggingpercentage']));

        return [
            'at_bats' => $this->toInt($this->mappedStat($mapped, ['ab', 'atbats']))
                ?? $combined['at_bats'],
            'runs' => $this->toInt($this->mappedStat($mapped, ['r', 'runs'])),
            'hits' => $this->toInt($this->mappedStat($mapped, ['h', 'hits']))
                ?? $combined['hits'],
            'doubles' => $this->toInt($this->mappedStat($mapped, ['2b', 'doubles'])),
            'triples' => $this->toInt($this->mappedStat($mapped, ['3b', 'triples'])),
            'home_runs' => $this->toInt($this->mappedStat($mapped, ['hr', 'homeruns'])),
            'rbis' => $this->toInt($this->mappedStat($mapped, ['rbi', 'rbis'])),
            'walks' => $this->toInt($this->mappedStat($mapped, ['bb', 'walks'])),
            'strikeouts' => $this->toInt($this->mappedStat($mapped, ['k', 'so', 'strikeouts'])),
            'stolen_bases' => $this->toInt($this->mappedStat($mapped, ['sb', 'stolenbases'])),
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
                $this->mappedStat($mapped, ['ip', 'inningspitched'])
            ),
            'hits_allowed' => $this->toInt($this->mappedStat($mapped, ['h', 'hits'])),
            'runs_allowed' => $this->toInt($this->mappedStat($mapped, ['r', 'runs'])),
            'earned_runs' => $this->toInt($this->mappedStat($mapped, ['er', 'earnedruns'])),
            'walks_allowed' => $this->toInt($this->mappedStat($mapped, ['bb', 'walks'])),
            'strikeouts_pitched' => $this->toInt($this->mappedStat($mapped, ['k', 'so', 'strikeouts'])),
            'home_runs_allowed' => $this->toInt($this->mappedStat($mapped, ['hr', 'homeruns'])),
            'era' => ($era = $this->mappedStat($mapped, ['era'])) === null ? null : ObservedStatValue::decimal($era, maximum: 999.99),
            'pitches_thrown' => $this->toInt($this->mappedStat($mapped, ['pitches', 'pit', 'p', 'pitchcount', 'pc'])),
            'pitch_count' => $this->toInt($this->mappedStat($mapped, ['pitchcount', 'pc', 'pitches', 'pit', 'p'])),
        ];
    }

    protected function parseFieldingStats(array $stats, array $labels = []): array
    {
        $mapped = $this->statsByLabel($labels, $stats);

        return [
            'putouts' => $this->toInt($this->mappedStat($mapped, ['po', 'putouts'])),
            'assists' => $this->toInt($this->mappedStat($mapped, ['a', 'assists'])),
            'errors' => $this->toInt($this->mappedStat($mapped, ['e', 'errors'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statsByLabel(array $labels, array $stats): array
    {
        return array_change_key_case(ObservedStatValue::labeled($labels, $stats, true), CASE_LOWER);
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<int, string>  $candidates
     */
    private function mappedStat(array $mapped, array $candidates): mixed
    {
        $found = false;
        $value = null;
        foreach ($candidates as $candidate) {
            $key = $this->normalizeLabel($candidate);
            if ($key === '' || ! array_key_exists($key, $mapped)) {
                continue;
            }
            $next = $mapped[$key];
            if ($next === null) {
                throw new DomainException('Missing labeled observation.');
            }
            if ($found) {
                $same = is_numeric($next) && is_numeric($value)
                    ? ObservedStatValue::decimal($next) === ObservedStatValue::decimal($value)
                    : (is_string($next) && is_string($value) && trim($next) === trim($value));
                if (! $same) {
                    throw new DomainException('Conflicting canonical aliases.');
                }
            }
            $value = $next;
            $found = true;
        }

        return $value;
    }

    private function normalizeLabel(string $label): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $label));
    }

    private function toInt(mixed $value): ?int
    {
        return $value === null ? null : ObservedStatValue::integer($value);
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null ? null : ObservedStatValue::decimal($value);
    }

    /** @return array{hits:?int,at_bats:?int} */
    private function parseHitsAtBats(mixed $value): array
    {
        if ($value === null) {
            return ['hits' => null, 'at_bats' => null];
        }
        [$hits, $atBats] = ObservedStatValue::pair($value, '-');

        return ['hits' => $hits, 'at_bats' => $atBats];
    }

    private function sanitizeRate(?float $value, float $max = 1.0): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0 || $value > $max) {
            throw new DomainException('Observed rate is outside its domain.');
        }

        return $value;
    }
}
