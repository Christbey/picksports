<?php

namespace App\Services\TeamStats;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\CBB\TeamStat as CbbStat;
use App\Models\MLB\TeamStat as MlbStat;
use App\Models\NBA\TeamStat as NbaStat;
use App\Models\WCBB\TeamStat as WcbbStat;
use App\Models\WNBA\TeamStat as WnbaStat;
use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Services\Api\V2\SportContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class CompletedTeamStatScope
{
    use BuildsSportQueries;

    private const MODELS = [NbaStat::class => 'nba', CbbStat::class => 'cbb', WcbbStat::class => 'wcbb', WnbaStat::class => 'wnba', MlbStat::class => 'mlb'];

    public function __construct(private readonly SportContextResolver $sports) {}

    public static function phases(string $sport): array
    {
        $sport = strtolower(trim($sport));
        $types = (array) config("{$sport}.season.types", []);
        $names = array_intersect_key((array) config("{$sport}.season.type_names", []), $types);

        return array_values(array_unique(array_map('strval', ['all', ...array_keys($types), ...array_values($types), ...array_values($names)])));
    }

    /** @param class-string<Model> $statClass
     * @return array{query:Builder,metadata:array<string,mixed>,table:string}
     */
    public function resolve(string $statClass, array $filters = [], ?int $teamId = null): array
    {
        $sport = null;
        foreach (self::MODELS as $supported => $slug) {
            if (is_a($statClass, $supported, true)) {
                $sport = $slug;
                break;
            }
        }
        if ($sport === null) {
            throw new InvalidArgumentException('Season averages require a supported team-stat model.');
        }
        $stat = new $statClass;
        $context = $this->sports->resolve($sport);
        $game = $stat->game()->getRelated();
        $expectedGame = $context->models['game'];
        if (! ($game instanceof $expectedGame) || $stat->getConnection()->getName() !== $game->getConnection()->getName()) {
            throw new LogicException('Season averages require one coherent stat/game connection and sport.');
        }
        $table = $stat->getTable();
        $games = $game->getTable();
        $phase = (string) ($filters['season_type'] ?? config("{$sport}.season.default_team_metrics_type", config("{$sport}.season.types.regular")));
        if (! in_array($phase, self::phases($sport), true)) {
            throw new InvalidArgumentException('Unknown season-average phase.');
        }
        $types = (array) config("{$sport}.season.types", []);
        $names = (array) config("{$sport}.season.type_names", []);
        $key = array_search($phase, $names, true);
        $canonicalPhase = isset($types[$phase]) ? (string) $types[$phase] : ($key !== false && isset($types[$key]) ? (string) $types[$key] : $phase);
        $query = $stat->newQuery()->join($games, "{$table}.game_id", '=', "{$games}.id")
            ->whereIn("{$games}.status", GameData::finalStatuses())
            ->where("{$games}.home_team_id", '>', 0)->where("{$games}.away_team_id", '>', 0)
            ->whereColumn("{$games}.home_team_id", '!=', "{$games}.away_team_id")
            ->where(function (Builder $query) use ($table, $games): void {
                $query->whereColumn("{$table}.team_id", "{$games}.home_team_id")
                    ->orWhereColumn("{$table}.team_id", "{$games}.away_team_id");
            });
        if ($teamId !== null) {
            $query->where("{$table}.team_id", $teamId);
        }
        if ($phase !== 'all') {
            $query->whereIn("{$games}.season_type", $this->gameSeasonTypeCandidates($context, $phase));
        }
        $explicit = array_key_exists('season', $filters);
        $season = $explicit ? $filters['season'] : (clone $query)->max("{$games}.season");
        if ($season !== null && ((! is_int($season) && (! is_string($season) || preg_match('/^[0-9]{1,4}$/D', $season) !== 1)) || (int) $season < 1 || (int) $season > 9999)) {
            throw new InvalidArgumentException('Invalid season-average season.');
        }
        $season = $season === null ? null : (int) $season;
        if ($season === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where("{$games}.season", $season);
        }

        return ['query' => $query, 'table' => $table, 'metadata' => ['season' => $season, 'season_type' => $canonicalPhase,
            'selection' => $explicit ? 'explicit' : 'latest_stored_completed',
            'phase_selection' => array_key_exists('season_type', $filters) ? 'explicit' : 'configured_default',
            'basis' => 'completed_game_team_stats']];
    }
}
