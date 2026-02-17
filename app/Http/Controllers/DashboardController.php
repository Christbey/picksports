<?php

namespace App\Http\Controllers;

use App\Actions\CBB\CalculateBettingValue as CBBCalculateBettingValue;
use App\Actions\NBA\CalculateBettingValue as NBACalculateBettingValue;
use App\Actions\NFL\CalculateBettingValue as NFLCalculateBettingValue;
use App\Models\CBB\Game as CBBGame;
use App\Models\CBB\Prediction as CBBPrediction;
use App\Models\CFB\Game as CFBGame;
use App\Models\CFB\Prediction as CFBPrediction;
use App\Models\Healthcheck;
use App\Models\MLB\Game as MLBGame;
use App\Models\MLB\Prediction as MLBPrediction;
use App\Models\NBA\Game as NBAGame;
use App\Models\NBA\Prediction as NBAPrediction;
use App\Models\NFL\Game as NFLGame;
use App\Models\NFL\Prediction as NFLPrediction;
use App\Models\WCBB\Game as WCBBGame;
use App\Models\WCBB\Prediction as WCBBPrediction;
use App\Models\WNBA\Game as WNBAGame;
use App\Models\WNBA\Prediction as WNBAPrediction;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        // Get today's predictions from all sports
        $todaysPredictions = collect();
        $today = now()->toDateString();

        // Add NBA predictions
        $nba = NBAPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereDate('game_date', $today))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD']);
                $isFinal = in_array($p->game->status, ['STATUS_FINAL', 'STATUS_FULL_TIME']);

                return [
                    'sport' => 'NBA',
                    'game_id' => $p->game->id,
                    'game' => $p->game->name,
                    'game_time' => $p->game->game_date,
                    'home_team' => $p->game->homeTeam->abbreviation,
                    'away_team' => $p->game->awayTeam->abbreviation,
                    'win_probability' => (float) $p->win_probability,
                    'predicted_spread' => (float) $p->predicted_spread,
                    'predicted_total' => (float) $p->predicted_total,
                    'home_logo' => $p->game->homeTeam->logo_url,
                    'away_logo' => $p->game->awayTeam->logo_url,
                    'betting_value' => app(NBACalculateBettingValue::class)->execute($p->game),
                    // Live game data
                    'is_live' => $isLive,
                    'is_final' => $isFinal,
                    'home_score' => ($isLive || $isFinal) ? $p->game->home_score : null,
                    'away_score' => ($isLive || $isFinal) ? $p->game->away_score : null,
                    'period' => $isLive ? $p->game->period : null,
                    'game_clock' => $isLive ? $p->game->game_clock : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive && $p->live_win_probability !== null ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive && $p->live_predicted_spread !== null ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive && $p->live_predicted_total !== null ? (float) $p->live_predicted_total : null,
                    'live_seconds_remaining' => $isLive ? $p->live_seconds_remaining : null,
                ];
            });
        $todaysPredictions = $todaysPredictions->merge($nba);

        // Add CBB predictions
        $cbb = CBBPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereDate('game_date', $today))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD']);
                $isFinal = in_array($p->game->status, ['STATUS_FINAL', 'STATUS_FULL_TIME']);

                return [
                    'sport' => 'CBB',
                    'game_id' => $p->game->id,
                    'game' => $p->game->name,
                    'game_time' => $p->game->game_date,
                    'home_team' => $p->game->homeTeam->abbreviation,
                    'away_team' => $p->game->awayTeam->abbreviation,
                    'win_probability' => (float) $p->win_probability,
                    'predicted_spread' => (float) $p->predicted_spread,
                    'predicted_total' => (float) $p->predicted_total,
                    'home_logo' => $p->game->homeTeam->logo_url,
                    'away_logo' => $p->game->awayTeam->logo_url,
                    'betting_value' => app(CBBCalculateBettingValue::class)->execute($p->game),
                    // Live game data
                    'is_live' => $isLive,
                    'is_final' => $isFinal,
                    'home_score' => ($isLive || $isFinal) ? $p->game->home_score : null,
                    'away_score' => ($isLive || $isFinal) ? $p->game->away_score : null,
                    'period' => $isLive ? $p->game->period : null,
                    'game_clock' => $isLive ? $p->game->game_clock : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive && $p->live_win_probability !== null ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive && $p->live_predicted_spread !== null ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive && $p->live_predicted_total !== null ? (float) $p->live_predicted_total : null,
                    'live_seconds_remaining' => $isLive ? $p->live_seconds_remaining : null,
                ];
            });
        $todaysPredictions = $todaysPredictions->merge($cbb);

        // Add WCBB predictions
        $wcbb = WCBBPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereDate('game_date', $today))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD']);
                $isFinal = in_array($p->game->status, ['STATUS_FINAL', 'STATUS_FULL_TIME']);

                return [
                    'sport' => 'WCBB',
                    'game_id' => $p->game->id,
                    'game' => $p->game->name,
                    'game_time' => $p->game->game_date,
                    'home_team' => $p->game->homeTeam->abbreviation,
                    'away_team' => $p->game->awayTeam->abbreviation,
                    'win_probability' => (float) $p->win_probability,
                    'predicted_spread' => (float) $p->predicted_spread,
                    'predicted_total' => (float) $p->predicted_total,
                    'home_logo' => $p->game->homeTeam->logo_url,
                    'away_logo' => $p->game->awayTeam->logo_url,
                    // Live game data
                    'is_live' => $isLive,
                    'is_final' => $isFinal,
                    'home_score' => ($isLive || $isFinal) ? $p->game->home_score : null,
                    'away_score' => ($isLive || $isFinal) ? $p->game->away_score : null,
                    'period' => $isLive ? $p->game->period : null,
                    'game_clock' => $isLive ? $p->game->game_clock : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive && $p->live_win_probability !== null ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive && $p->live_predicted_spread !== null ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive && $p->live_predicted_total !== null ? (float) $p->live_predicted_total : null,
                    'live_seconds_remaining' => $isLive ? $p->live_seconds_remaining : null,
                ];
            });
        $todaysPredictions = $todaysPredictions->merge($wcbb);

        // Add NFL predictions
        $nfl = NFLPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereDate('game_date', $today))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD']);
                $isFinal = in_array($p->game->status, ['STATUS_FINAL', 'STATUS_FULL_TIME']);

                return [
                    'sport' => 'NFL',
                    'game_id' => $p->game->id,
                    'game' => $p->game->name,
                    'game_time' => $p->game->game_date,
                    'home_team' => $p->game->homeTeam->abbreviation,
                    'away_team' => $p->game->awayTeam->abbreviation,
                    'win_probability' => (float) $p->win_probability,
                    'predicted_spread' => (float) $p->predicted_spread,
                    'predicted_total' => (float) $p->predicted_total,
                    'home_logo' => $p->game->homeTeam->logo_url,
                    'away_logo' => $p->game->awayTeam->logo_url,
                    'betting_value' => app(NFLCalculateBettingValue::class)->execute($p->game),
                    // Live game data
                    'is_live' => $isLive,
                    'is_final' => $isFinal,
                    'home_score' => ($isLive || $isFinal) ? $p->game->home_score : null,
                    'away_score' => ($isLive || $isFinal) ? $p->game->away_score : null,
                    'period' => $isLive ? $p->game->period : null,
                    'game_clock' => $isLive ? $p->game->game_clock : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive && $p->live_win_probability !== null ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive && $p->live_predicted_spread !== null ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive && $p->live_predicted_total !== null ? (float) $p->live_predicted_total : null,
                    'live_seconds_remaining' => $isLive ? $p->live_seconds_remaining : null,
                ];
            });
        $todaysPredictions = $todaysPredictions->merge($nfl);

        // Add MLB predictions
        $mlb = MLBPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereDate('game_date', $today))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_DELAYED']);
                $isFinal = in_array($p->game->status, ['STATUS_FINAL', 'STATUS_FULL_TIME']);

                return [
                    'sport' => 'MLB',
                    'game_id' => $p->game->id,
                    'game' => $p->game->name,
                    'game_time' => $p->game->game_date,
                    'home_team' => $p->game->homeTeam->abbreviation,
                    'away_team' => $p->game->awayTeam->abbreviation,
                    'win_probability' => (float) $p->win_probability,
                    'predicted_spread' => (float) $p->predicted_spread,
                    'predicted_total' => (float) $p->predicted_total,
                    'home_logo' => $p->game->homeTeam->logo_url,
                    'away_logo' => $p->game->awayTeam->logo_url,
                    // Live game data
                    'is_live' => $isLive,
                    'is_final' => $isFinal,
                    'home_score' => ($isLive || $isFinal) ? $p->game->home_score : null,
                    'away_score' => ($isLive || $isFinal) ? $p->game->away_score : null,
                    'inning' => $isLive ? $p->game->inning : null,
                    'inning_state' => $isLive ? $p->game->inning_state : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive && $p->live_win_probability !== null ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive && $p->live_predicted_spread !== null ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive && $p->live_predicted_total !== null ? (float) $p->live_predicted_total : null,
                    'live_outs_remaining' => $isLive ? $p->live_outs_remaining : null,
                ];
            });
        $todaysPredictions = $todaysPredictions->merge($mlb);

        // Add CFB predictions
        $cfb = CFBPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereDate('game_date', $today))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD']);
                $isFinal = in_array($p->game->status, ['STATUS_FINAL', 'STATUS_FULL_TIME']);

                return [
                    'sport' => 'CFB',
                    'game_id' => $p->game->id,
                    'game' => $p->game->name,
                    'game_time' => $p->game->game_date,
                    'home_team' => $p->game->homeTeam->abbreviation,
                    'away_team' => $p->game->awayTeam->abbreviation,
                    'win_probability' => (float) $p->win_probability,
                    'predicted_spread' => (float) $p->predicted_spread,
                    'predicted_total' => (float) $p->predicted_total,
                    'home_logo' => $p->game->homeTeam->logo_url,
                    'away_logo' => $p->game->awayTeam->logo_url,
                    // Live game data
                    'is_live' => $isLive,
                    'is_final' => $isFinal,
                    'home_score' => ($isLive || $isFinal) ? $p->game->home_score : null,
                    'away_score' => ($isLive || $isFinal) ? $p->game->away_score : null,
                    'period' => $isLive ? $p->game->period : null,
                    'game_clock' => $isLive ? $p->game->game_clock : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive && $p->live_win_probability !== null ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive && $p->live_predicted_spread !== null ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive && $p->live_predicted_total !== null ? (float) $p->live_predicted_total : null,
                    'live_seconds_remaining' => $isLive ? $p->live_seconds_remaining : null,
                ];
            });
        $todaysPredictions = $todaysPredictions->merge($cfb);

        // Add WNBA predictions
        $wnba = WNBAPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereDate('game_date', $today))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD']);
                $isFinal = in_array($p->game->status, ['STATUS_FINAL', 'STATUS_FULL_TIME']);

                return [
                    'sport' => 'WNBA',
                    'game_id' => $p->game->id,
                    'game' => $p->game->name,
                    'game_time' => $p->game->game_date,
                    'home_team' => $p->game->homeTeam->abbreviation,
                    'away_team' => $p->game->awayTeam->abbreviation,
                    'win_probability' => (float) $p->win_probability,
                    'predicted_spread' => (float) $p->predicted_spread,
                    'predicted_total' => (float) $p->predicted_total,
                    'home_logo' => $p->game->homeTeam->logo_url,
                    'away_logo' => $p->game->awayTeam->logo_url,
                    // Live game data
                    'is_live' => $isLive,
                    'is_final' => $isFinal,
                    'home_score' => ($isLive || $isFinal) ? $p->game->home_score : null,
                    'away_score' => ($isLive || $isFinal) ? $p->game->away_score : null,
                    'period' => $isLive ? $p->game->period : null,
                    'game_clock' => $isLive ? $p->game->game_clock : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive && $p->live_win_probability !== null ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive && $p->live_predicted_spread !== null ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive && $p->live_predicted_total !== null ? (float) $p->live_predicted_total : null,
                    'live_seconds_remaining' => $isLive ? $p->live_seconds_remaining : null,
                ];
            });
        $todaysPredictions = $todaysPredictions->merge($wnba);

        // Apply user's tier limit on predictions per day
        $user = auth()->user();
        $predictionsPerDay = $user->subscriptionTier()?->features['predictions_per_day'] ?? null;

        if ($predictionsPerDay !== null) {
            $todaysPredictions = $todaysPredictions->sortBy('game_time')->take($predictionsPerDay);
        }

        // Group predictions by sport and sort each group by game time
        $predictionsBySport = $todaysPredictions
            ->groupBy('sport')
            ->map(fn ($predictions) => $predictions->sortBy('game_time')->values());

        // Define sport order and metadata
        $sports = collect([
            'NBA' => ['name' => 'NBA', 'fullName' => 'National Basketball Association', 'color' => 'orange'],
            'CBB' => ['name' => 'CBB', 'fullName' => "Men's College Basketball", 'color' => 'blue'],
            'WCBB' => ['name' => 'WCBB', 'fullName' => "Women's College Basketball", 'color' => 'purple'],
            'NFL' => ['name' => 'NFL', 'fullName' => 'National Football League', 'color' => 'green'],
            'MLB' => ['name' => 'MLB', 'fullName' => 'Major League Baseball', 'color' => 'orange'],
            'CFB' => ['name' => 'CFB', 'fullName' => 'College Football', 'color' => 'blue'],
            'WNBA' => ['name' => 'WNBA', 'fullName' => "Women's National Basketball Association", 'color' => 'purple'],
        ])->map(function ($sport, $key) use ($predictionsBySport) {
            return array_merge($sport, [
                'predictions' => $predictionsBySport->get($key, collect())->values(),
            ]);
        })->filter(fn ($sport) => $sport['predictions']->isNotEmpty())->values();

        // Get stats for top sections
        $stats = [
            'total_predictions_today' => $todaysPredictions->count(),
            'total_games_today' => NBAGame::whereDate('game_date', $today)->count()
                + CBBGame::whereDate('game_date', $today)->count()
                + WCBBGame::whereDate('game_date', $today)->count()
                + NFLGame::whereDate('game_date', $today)->count()
                + MLBGame::whereDate('game_date', $today)->count()
                + CFBGame::whereDate('game_date', $today)->count()
                + WNBAGame::whereDate('game_date', $today)->count(),
            'healthcheck_status' => Healthcheck::where('checked_at', '>=', now()->subHours(1))
                ->where('status', 'failing')
                ->exists() ? 'failing' : 'passing',
        ];

        return Inertia::render('Dashboard', [
            'sports' => $sports,
            'stats' => $stats,
        ]);
    }
}
