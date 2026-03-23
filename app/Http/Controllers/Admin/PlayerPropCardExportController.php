<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BettingRecommendationResource;
use App\Models\CBB\Prediction as CbbPrediction;
use App\Models\CBB\TournamentForecast as CbbTournamentForecast;
use App\Models\MLB\PlayoffForecast as MlbPlayoffForecast;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\NBA\PlayoffForecast as NbaPlayoffForecast;
use App\Models\NBA\Prediction as NbaPrediction;
use App\Models\NFL\Prediction as NflPrediction;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlayerPropCardExportController extends Controller
{
    public function __construct(
        protected PlayerPropAnalyzer $analyzer
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'sport' => ['nullable', 'in:NBA,NFL,MLB,CBB'],
            'date' => ['nullable', 'date'],
            'game' => ['nullable', 'integer'],
            'market' => ['nullable', 'string', 'max:100'],
            'tab' => ['nullable', 'in:props,predictions,futures,tournament'],
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $sport = $validated['sport'] ?? 'NBA';
        $dateFilter = $validated['date'] ?? null;
        $gameFilter = isset($validated['game']) ? (int) $validated['game'] : null;
        $marketFilter = $validated['market'] ?? null;
        $seasonFilter = isset($validated['season']) ? (int) $validated['season'] : null;
        $activeTab = $validated['tab'] ?? 'props';

        $dates = $this->analyzer->getAvailableDatesForSport($sport);
        $games = $this->analyzer->getAvailableGamesForSport($sport, $dateFilter);
        $markets = $this->analyzer->getAvailableMarketsForSport($sport, $dateFilter, $gameFilter);

        $recommendations = $this->analyzer
            ->analyzeProps(
                sport: $sport,
                minGames: 3,
                dateFilter: $dateFilter,
                gameFilter: $gameFilter,
                marketFilter: $marketFilter
            )
            ->take(24);

        $predictionDates = $this->getPredictionDateOptions($sport);
        $predictionGames = $this->getPredictionGameOptions($sport, $dateFilter);
        $predictions = $this->getPredictionExports($sport, $dateFilter, $gameFilter);
        $futuresSeasons = $this->getFuturesSeasonOptions($sport);
        $futures = $this->getFuturesExports($sport, $seasonFilter);
        $tournamentSeasons = $this->getTournamentSeasonOptions($sport);
        $tournaments = $this->getTournamentExports($sport, $seasonFilter);

        return Inertia::render('Admin/PlayerPropExports', [
            'activeTab' => $activeTab,
            'sport' => $sport,
            'recommendations' => BettingRecommendationResource::collection($recommendations)->resolve(),
            'predictions' => $predictions,
            'futures' => $futures,
            'tournaments' => $tournaments,
            'dates' => $dates,
            'games' => $games,
            'markets' => $markets,
            'predictionDates' => $predictionDates,
            'predictionGames' => $predictionGames,
            'futuresSeasons' => $futuresSeasons,
            'tournamentSeasons' => $tournamentSeasons,
            'filters' => [
                'sport' => $sport,
                'date' => $dateFilter,
                'game' => $gameFilter,
                'market' => $marketFilter,
                'tab' => $activeTab,
                'season' => $seasonFilter,
            ],
        ]);
    }

    protected function getPredictionModel(string $sport): string
    {
        return match ($sport) {
            'NBA' => NbaPrediction::class,
            'NFL' => NflPrediction::class,
            'MLB' => MlbPrediction::class,
            'CBB' => CbbPrediction::class,
            default => NbaPrediction::class,
        };
    }

    protected function getPredictionDateOptions(string $sport): array
    {
        $modelClass = $this->getPredictionModel($sport);
        $prediction = new $modelClass;
        $table = $prediction->getTable();
        $gameTable = str_replace('_predictions', '_games', $table);

        return $modelClass::query()
            ->join($gameTable, "{$gameTable}.id", '=', "{$table}.game_id")
            ->selectRaw("DATE({$gameTable}.game_date) as game_date")
            ->whereNotNull("{$gameTable}.game_date")
            ->distinct()
            ->orderByDesc('game_date')
            ->limit(45)
            ->get()
            ->map(fn ($row) => [
                'value' => $row->game_date,
                'label' => Carbon::parse($row->game_date)->format('M j, Y'),
            ])
            ->values()
            ->all();
    }

    protected function getPredictionGameOptions(string $sport, ?string $dateFilter): array
    {
        $modelClass = $this->getPredictionModel($sport);
        $query = $modelClass::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function (Builder $gameQuery) use ($dateFilter): void {
                if ($dateFilter) {
                    $gameQuery->whereDate('game_date', $dateFilter);
                }
            })
            ->orderByDesc('id')
            ->limit(80);

        return $query->get()
            ->map(function ($prediction) {
                $game = $prediction->game;
                if (! $game) {
                    return null;
                }

                $home = $game->homeTeam->abbreviation ?? $game->homeTeam->name ?? 'Home';
                $away = $game->awayTeam->abbreviation ?? $game->awayTeam->name ?? 'Away';
                $date = optional($game->game_date)->format('Y-m-d');
                $time = optional($game->game_date)->format('g:i A');

                return [
                    'id' => $game->id,
                    'label' => "{$away} @ {$home}",
                    'date' => $date,
                    'time' => $time,
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    protected function getPredictionExports(string $sport, ?string $dateFilter, ?int $gameFilter): array
    {
        $modelClass = $this->getPredictionModel($sport);
        $rows = $modelClass::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function (Builder $gameQuery) use ($dateFilter, $gameFilter): void {
                if ($dateFilter) {
                    $gameQuery->whereDate('game_date', $dateFilter);
                }
                if ($gameFilter) {
                    $gameQuery->where('id', $gameFilter);
                }
            })
            ->orderByDesc('confidence_score')
            ->limit(24)
            ->get();

        return $rows->map(function ($prediction) {
            $game = $prediction->game;
            $home = $game?->homeTeam;
            $away = $game?->awayTeam;

            $homeName = $home?->abbreviation ?? $home?->name ?? 'Home';
            $awayName = $away?->abbreviation ?? $away?->name ?? 'Away';
            $winProbability = (float) ($prediction->win_probability ?? 0.5);
            $homeWinPct = $winProbability <= 1 ? $winProbability * 100 : $winProbability;
            $pickSide = $homeWinPct >= 50 ? 'Home' : 'Away';
            $pickTeam = $pickSide === 'Home' ? $homeName : $awayName;

            return [
                'id' => $prediction->id,
                'game_id' => $prediction->game_id,
                'home_team' => $homeName,
                'away_team' => $awayName,
                'game' => [
                    'home_team' => $homeName,
                    'away_team' => $awayName,
                    'date' => optional($game?->game_date)->format('Y-m-d'),
                    'time' => optional($game?->game_date)->format('g:i A'),
                ],
                'predicted_spread' => $prediction->predicted_spread,
                'predicted_total' => $prediction->predicted_total,
                'win_probability' => round($homeWinPct, 1),
                'confidence' => round((float) ($prediction->confidence_score ?? 0), 1),
                'home_elo' => $prediction->home_elo ?? $prediction->home_team_elo ?? $prediction->home_combined_elo,
                'away_elo' => $prediction->away_elo ?? $prediction->away_team_elo ?? $prediction->away_combined_elo,
                'pick_side' => $pickSide,
                'pick_team' => $pickTeam,
            ];
        })->values()->all();
    }

    protected function getFuturesModel(string $sport): ?string
    {
        return match ($sport) {
            'NBA' => NbaPlayoffForecast::class,
            'MLB' => MlbPlayoffForecast::class,
            default => null,
        };
    }

    protected function getFuturesSeasonOptions(string $sport): array
    {
        $modelClass = $this->getFuturesModel($sport);
        if (! $modelClass) {
            return [];
        }

        return $modelClass::query()
            ->select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->map(fn ($season) => ['value' => (string) $season, 'label' => (string) $season])
            ->values()
            ->all();
    }

    protected function getFuturesExports(string $sport, ?int $seasonFilter): array
    {
        $modelClass = $this->getFuturesModel($sport);
        if (! $modelClass) {
            return [];
        }

        $season = $seasonFilter ?? (int) ($modelClass::query()->max('season') ?? 0);
        if ($season <= 0) {
            return [];
        }

        $rows = $modelClass::query()
            ->with('team')
            ->where('season', $season)
            ->orderByDesc('champion_probability')
            ->orderByDesc('playoff_make_probability')
            ->limit(24)
            ->get();

        return $rows->map(function ($row) use ($sport) {
            $team = $row->team;
            $teamName = $team?->abbreviation ?? $team?->name ?? (string) $row->team_id;
            $playoffPct = round(((float) ($row->playoff_make_probability ?? 0)) * 100, 1);
            $titlePct = round(((float) ($row->champion_probability ?? 0)) * 100, 2);

            return [
                'id' => $row->id,
                'team' => $teamName,
                'season' => $row->season,
                'projected_seed' => $row->projected_seed,
                'playoff_make_probability' => $playoffPct,
                'champion_probability' => $titlePct,
                'conference_or_league' => $row->conference ?? $row->league ?? null,
                'conference_finals_probability' => isset($row->conference_finals_probability)
                    ? round(((float) $row->conference_finals_probability) * 100, 1)
                    : null,
                'nba_finals_probability' => isset($row->nba_finals_probability)
                    ? round(((float) $row->nba_finals_probability) * 100, 1)
                    : null,
                'world_series_probability' => isset($row->world_series_probability)
                    ? round(((float) $row->world_series_probability) * 100, 1)
                    : null,
                'league_championship_probability' => isset($row->league_championship_probability)
                    ? round(((float) $row->league_championship_probability) * 100, 1)
                    : null,
                'sport' => $sport,
            ];
        })->values()->all();
    }

    protected function getTournamentSeasonOptions(string $sport): array
    {
        if ($sport !== 'CBB') {
            return [];
        }

        return CbbTournamentForecast::query()
            ->select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->map(fn ($season) => ['value' => (string) $season, 'label' => (string) $season])
            ->values()
            ->all();
    }

    protected function getTournamentExports(string $sport, ?int $seasonFilter): array
    {
        if ($sport !== 'CBB') {
            return [];
        }

        $season = $seasonFilter ?? (int) (CbbTournamentForecast::query()->max('season') ?? 0);
        if ($season <= 0) {
            return [];
        }

        $rows = CbbTournamentForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderByDesc('champion_probability')
            ->orderByDesc('tournament_make_probability')
            ->limit(24)
            ->get();

        return $rows->map(function ($row) {
            $team = $row->team;
            $teamName = $team?->abbreviation ?? $team?->school ?? (string) $row->team_id;

            return [
                'id' => $row->id,
                'team' => $teamName,
                'season' => $row->season,
                'conference' => $team?->conference,
                'projected_seed' => $row->projected_seed,
                'tournament_make_probability' => round(((float) ($row->tournament_make_probability ?? 0)) * 100, 1),
                'champion_probability' => round(((float) ($row->champion_probability ?? 0)) * 100, 2),
                'auto_bid_probability' => round(((float) ($row->auto_bid_probability ?? 0)) * 100, 1),
                'at_large_probability' => round(((float) ($row->at_large_probability ?? 0)) * 100, 1),
                'first_four_probability' => round(((float) ($row->first_four_probability ?? 0)) * 100, 1),
                'bid_thief_probability' => round(((float) ($row->bid_thief_probability ?? 0)) * 100, 1),
            ];
        })->values()->all();
    }
}
