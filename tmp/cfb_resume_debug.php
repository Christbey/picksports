<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Support\CfbSeasonAffiliationResolver;

$season = 2024;
$resolver = app(CfbSeasonAffiliationResolver::class);
$targets = [
    'Georgia Bulldogs',
    'Texas Longhorns',
    'Penn State Nittany Lions',
];

foreach ($targets as $target) {
    $team = Team::query()
        ->whereRaw("concat(trim(coalesce(school,'')),' ',trim(coalesce(mascot,''))) = ?", [$target])
        ->first();

    if (! $team) {
        echo "TEAM_NOT_FOUND:{$target}\n";

        continue;
    }

    $games = Game::query()
        ->where('season', $season)
        ->where('status', config('cfb.statuses.final'))
        ->where(function ($query) use ($team): void {
            $query->where('home_team_id', $team->id)
                ->orWhere('away_team_id', $team->id);
        })
        ->with(['homeTeam', 'awayTeam'])
        ->orderBy('game_date')
        ->get();

    $eloIndex = EloRating::query()
        ->whereIn('game_id', $games->pluck('id'))
        ->get()
        ->mapWithKeys(function ($record): array {
            $preGameElo = (float) $record->elo_rating - (float) $record->elo_change;

            return [$record->game_id.'-'.$record->team_id => $preGameElo];
        })
        ->all();

    $wins = 0;
    $opponentElos = [];

    foreach ($games as $game) {
        $isHome = (int) $game->home_team_id === (int) $team->id;
        $teamScore = (int) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
        $oppScore = (int) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));

        if ($teamScore > $oppScore) {
            $wins++;
        }

        $opponent = $isHome ? $game->awayTeam : $game->homeTeam;
        if ($opponent) {
            $opponentElos[] = $eloIndex[$game->id.'-'.$opponent->id] ?? (float) ($opponent->elo_rating ?? 1500);
        }
    }

    $baseResume = ($wins / max(1, $games->count())) * 55.0;
    $sos = $opponentElos === [] ? null : (array_sum($opponentElos) / count($opponentElos));
    $baseResume += (($sos ?? 1500.0) - 1500.0) * 0.030;

    echo "== {$target} ==\n";
    echo 'base_resume='.round($baseResume, 3).' sos='.round((float) $sos, 3).' games='.$games->count()."\n";

    foreach ($games as $game) {
        $isHome = (int) $game->home_team_id === (int) $team->id;
        $opponent = $isHome ? $game->awayTeam : $game->homeTeam;

        if (! $opponent) {
            continue;
        }

        $teamScore = (int) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
        $oppScore = (int) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));
        $margin = $teamScore - $oppScore;
        $won = $margin > 0;
        $oppIsFbs = $resolver->isFbs($opponent, $season);
        $oppPregameElo = $eloIndex[$game->id.'-'.$opponent->id] ?? (float) ($opponent->elo_rating ?? 1500);
        $neutral = (bool) ($game->neutral_site ?? false);
        $locationBonus = $neutral ? 0.25 : ($isHome ? 0.0 : 0.55);
        $championshipBonus = ((int) ($game->season_type ?? 0) === (int) config('cfb.season.types.postseason')
            && (int) ($game->week ?? 0) <= 2) ? 0.75 : 0.0;
        $marginBonus = min(1.25, max(-1.25, $margin / 14.0));

        if (! $oppIsFbs) {
            $delta = $won ? 0.15 : -6.0;
        } else {
            $qualityWinBonus = match (true) {
                $oppPregameElo >= 1600 => 4.8,
                $oppPregameElo >= 1550 => 3.8,
                $oppPregameElo >= 1500 => 2.8,
                $oppPregameElo >= 1450 => 1.8,
                default => 0.8,
            };

            $badLossPenalty = match (true) {
                $oppPregameElo < 1350 => -7.0,
                $oppPregameElo < 1400 => -5.0,
                $oppPregameElo < 1450 => -3.4,
                $oppPregameElo < 1500 => -2.0,
                default => -0.9,
            };

            if ($won) {
                $delta = 1.6 + $qualityWinBonus + $locationBonus + max(0.0, $marginBonus * 0.6) + $championshipBonus;
            } else {
                $homeLossPenalty = $isHome && ! $neutral ? -0.75 : 0.0;
                $delta = $badLossPenalty + $homeLossPenalty + min(0.0, $marginBonus * 0.4);
            }
        }

        $opponentName = trim(($opponent->school ?? '').' '.($opponent->mascot ?? ''));
        echo $game->game_date->toDateString()
            .' | '.($won ? 'W' : 'L')
            .' | '.$opponentName
            .' | opp_elo='.round($oppPregameElo, 1)
            .' | margin='.$margin
            .' | fbs='.($oppIsFbs ? 'Y' : 'N')
            .' | delta='.round($delta, 3)
            ."\n";
    }

    $losses = max(0, $games->count() - $wins);
    echo 'loss_penalty='.round($losses * 1.2, 3)."\n\n";
}
