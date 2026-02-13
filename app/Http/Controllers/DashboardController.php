<?php

namespace App\Http\Controllers;

use App\Actions\CBB\CalculateBettingValue as CBBCalculateBettingValue;
use App\Actions\NBA\CalculateBettingValue as NBACalculateBettingValue;
use App\Actions\NFL\CalculateBettingValue as NFLCalculateBettingValue;
use App\Models\CBB\Game as CBBGame;
use App\Models\CBB\Prediction as CBBPrediction;
use App\Models\Healthcheck;
use App\Models\NBA\Game as NBAGame;
use App\Models\NBA\Prediction as NBAPrediction;
use App\Models\NFL\Game as NFLGame;
use App\Models\NFL\Prediction as NFLPrediction;
use App\Models\WCBB\Game as WCBBGame;
use App\Models\WCBB\Prediction as WCBBPrediction;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        // Get upcoming predictions from all sports (next 24 hours)
        $upcomingPredictions = collect();

        // Add NBA predictions
        $nba = NBAPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_SCHEDULED')
                ->where('game_date', '>=', now())
                ->where('game_date', '<=', now()->addHours(24)))
            ->get()
            ->map(fn ($p) => [
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
            ]);
        $upcomingPredictions = $upcomingPredictions->merge($nba);

        // Add CBB predictions
        $cbb = CBBPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_SCHEDULED')
                ->where('game_date', '>=', now())
                ->where('game_date', '<=', now()->addHours(24)))
            ->get()
            ->map(fn ($p) => [
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
            ]);
        $upcomingPredictions = $upcomingPredictions->merge($cbb);

        // Add WCBB predictions
        $wcbb = WCBBPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_SCHEDULED')
                ->where('game_date', '>=', now())
                ->where('game_date', '<=', now()->addHours(24)))
            ->get()
            ->map(fn ($p) => [
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
            ]);
        $upcomingPredictions = $upcomingPredictions->merge($wcbb);

        // Add NFL predictions (include games from yesterday/today for games stored at midnight)
        $nfl = NFLPrediction::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', fn ($q) => $q->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'])
                ->where('game_date', '>=', now()->subDay()->startOfDay())
                ->where('game_date', '<=', now()->addHours(24)))
            ->get()
            ->map(function ($p) {
                $isLive = in_array($p->game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD']);

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
                    'home_score' => $isLive ? $p->game->home_score : null,
                    'away_score' => $isLive ? $p->game->away_score : null,
                    'period' => $isLive ? $p->game->period : null,
                    'game_clock' => $isLive ? $p->game->game_clock : null,
                    'status' => $p->game->status,
                    // Live prediction data
                    'live_win_probability' => $isLive ? (float) $p->live_win_probability : null,
                    'live_predicted_spread' => $isLive ? (float) $p->live_predicted_spread : null,
                    'live_predicted_total' => $isLive ? (float) $p->live_predicted_total : null,
                    'live_seconds_remaining' => $isLive ? $p->live_seconds_remaining : null,
                ];
            });
        $upcomingPredictions = $upcomingPredictions->merge($nfl);

        // Apply user's tier limit on predictions per day
        $user = auth()->user();
        $predictionsPerDay = $user->subscriptionTier()?->features['predictions_per_day'] ?? null;

        if ($predictionsPerDay !== null) {
            $upcomingPredictions = $upcomingPredictions->sortBy('game_time')->take($predictionsPerDay);
        }

        // Group predictions by sport and sort each group by game time
        $predictionsBySport = $upcomingPredictions
            ->groupBy('sport')
            ->map(fn ($predictions) => $predictions->sortBy('game_time')->values());

        // Define sport order and metadata
        $sports = collect([
            'NBA' => ['name' => 'NBA', 'fullName' => 'National Basketball Association', 'color' => 'orange'],
            'CBB' => ['name' => 'CBB', 'fullName' => "Men's College Basketball", 'color' => 'blue'],
            'WCBB' => ['name' => 'WCBB', 'fullName' => "Women's College Basketball", 'color' => 'purple'],
            'NFL' => ['name' => 'NFL', 'fullName' => 'National Football League', 'color' => 'green'],
        ])->map(function ($sport, $key) use ($predictionsBySport) {
            return array_merge($sport, [
                'predictions' => $predictionsBySport->get($key, collect())->values(),
            ]);
        })->filter(fn ($sport) => $sport['predictions']->isNotEmpty())->values();

        // Get stats for top sections
        $stats = [
            'total_predictions_today' => $upcomingPredictions->count(),
            'total_games_today' => NBAGame::where('game_date', '>=', now()->startOfDay())
                ->where('game_date', '<=', now()->endOfDay())
                ->count() +
                CBBGame::where('game_date', '>=', now()->startOfDay())
                    ->where('game_date', '<=', now()->endOfDay())
                    ->count() +
                WCBBGame::where('game_date', '>=', now()->startOfDay())
                    ->where('game_date', '<=', now()->endOfDay())
                    ->count() +
                NFLGame::where('game_date', '>=', now()->startOfDay())
                    ->where('game_date', '<=', now()->endOfDay())
                    ->count(),
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
