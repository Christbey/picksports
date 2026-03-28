<?php

namespace App\Services;

use App\Http\Resources\BettingRecommendationResource;
use App\Mail\DailyPredictionsDigestMail;
use App\Models\DailyDigestSend;
use App\Models\NBA\Prediction;
use App\Models\User;
use App\Services\AI\SportsAiContentService;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Support\TierAccessBypass;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DailyDigestService
{
    /**
     * @var array<string, array<string, class-string<Model>|bool>>
     */
    private const SPORT_MAP = [
        'nba' => ['prediction_model' => Prediction::class, 'player_props' => true],
        'nfl' => ['prediction_model' => \App\Models\NFL\Prediction::class, 'player_props' => true],
        'cbb' => ['prediction_model' => \App\Models\CBB\Prediction::class, 'player_props' => true],
        'wcbb' => ['prediction_model' => \App\Models\WCBB\Prediction::class, 'player_props' => false],
        'mlb' => ['prediction_model' => \App\Models\MLB\Prediction::class, 'player_props' => true],
        'cfb' => ['prediction_model' => \App\Models\CFB\Prediction::class, 'player_props' => false],
        'wnba' => ['prediction_model' => \App\Models\WNBA\Prediction::class, 'player_props' => false],
    ];

    private const DIGEST_CONFIDENCE_FLOOR = 50.0;

    private const DIGEST_CONFIDENCE_CAP = 95.0;

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $predictionPoolCache = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $playerPropPoolCache = [];

    public function __construct(
        private readonly PlayerPropAnalyzer $playerPropAnalyzer,
        private readonly TierAccessBypass $tierAccessBypass,
        private readonly SportsAiContentService $sportsAiContentService,
    ) {}

    public function sendDueDigests(?CarbonInterface $now = null): int
    {
        $now ??= now();

        if (! config('subscriptions.features.email_alerts_enabled', true)) {
            return 0;
        }

        $sent = 0;

        $users = User::query()
            ->with('alertPreference')
            ->whereNotNull('email')
            ->get();

        foreach ($users as $user) {
            if (! $this->isDueForDigest($user, $now)) {
                continue;
            }

            $payload = $this->buildDigestForUser($user, $now);

            if ($payload === null) {
                continue;
            }

            try {
                Mail::to($user)->send(new DailyPredictionsDigestMail(
                    user: $user,
                    summary: $payload['summary'],
                    predictions: $payload['predictions'],
                    playerProps: $payload['player_props'],
                ));

                DailyDigestSend::create([
                    'user_id' => $user->id,
                    'digest_date' => $now->toDateString(),
                    'sent_at' => $now,
                    'predictions_count' => count($payload['predictions']),
                    'player_props_count' => count($payload['player_props']),
                ]);

                $sent++;
            } catch (\Throwable $exception) {
                Log::error('Failed to send daily digest email.', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    public function isDueForDigest(User $user, CarbonInterface $now): bool
    {
        if (! $this->canReceiveDigestEmails($user)) {
            return false;
        }

        $preference = $user->alertPreference;

        if ($preference && $preference->daily_digest_subscribed === false) {
            return false;
        }

        $targetTime = $preference?->digest_time?->format('H:i') ?? '10:00';

        if ($targetTime !== $now->format('H:i')) {
            return false;
        }

        return ! DailyDigestSend::query()
            ->where('user_id', $user->id)
            ->whereDate('digest_date', $now->toDateString())
            ->exists();
    }

    /**
     * @return array{
     *   summary: array{headline:string,intro:string,highlights:array<int,string>},
     *   predictions: array<int, array<string, mixed>>,
     *   player_props: array<int, array<string, mixed>>
     * }|null
     */
    public function buildDigestForUser(User $user, ?CarbonInterface $now = null): ?array
    {
        $now ??= now();
        $sports = $this->eligibleSports();

        if ($sports->isEmpty()) {
            return null;
        }

        $predictions = $sports
            ->flatMap(fn (string $sport) => $this->predictionPoolForSport($sport, $now))
            ->shuffle()
            ->take(3)
            ->values()
            ->all();

        $playerProps = $sports
            ->flatMap(fn (string $sport) => $this->playerPropPoolForSport($sport, $now))
            ->shuffle()
            ->take(3)
            ->values()
            ->all();

        if ($predictions === [] && $playerProps === []) {
            return null;
        }

        $summary = $this->sportsAiContentService->generateDailyDigestSummary(
            $predictions,
            $playerProps,
            $sports->all(),
        ) ?? $this->buildDigestSummaryFallback($predictions, $playerProps, $sports->all());

        return [
            'summary' => $summary,
            'predictions' => $predictions,
            'player_props' => $playerProps,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function eligibleSports(): Collection
    {
        return collect(array_keys(self::SPORT_MAP))
            ->values();
    }

    private function canReceiveDigestEmails(User $user): bool
    {
        return is_string($user->email) && trim($user->email) !== '';
    }

    private function canAccessSport(User $user, string $sport): bool
    {
        if ($this->tierAccessBypass->shouldBypassTierChecks($user)) {
            return true;
        }

        return $user->canAccessSport($sport);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function predictionPoolForSport(string $sport, CarbonInterface $now): array
    {
        $cacheKey = $sport.'|'.$now->toDateString();

        if (array_key_exists($cacheKey, $this->predictionPoolCache)) {
            return $this->predictionPoolCache[$cacheKey];
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = self::SPORT_MAP[$sport]['prediction_model'];

        $predictions = $modelClass::query()
            ->whereHas('game', function ($query) use ($now) {
                $query->whereDate('game_date', $now->toDateString())
                    ->whereNotIn('status', ['STATUS_FINAL', 'final']);
            })
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->inRandomOrder()
            ->limit(18)
            ->get()
            ->map(fn (Model $prediction) => $this->transformPrediction($sport, $prediction))
            ->filter()
            ->values()
            ->all();

        return $this->predictionPoolCache[$cacheKey] = $predictions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function playerPropPoolForSport(string $sport, CarbonInterface $now): array
    {
        $cacheKey = $sport.'|'.$now->toDateString();

        if (array_key_exists($cacheKey, $this->playerPropPoolCache)) {
            return $this->playerPropPoolCache[$cacheKey];
        }

        if ((self::SPORT_MAP[$sport]['player_props'] ?? false) !== true) {
            return $this->playerPropPoolCache[$cacheKey] = [];
        }

        $recommendations = $this->playerPropAnalyzer
            ->analyzeProps(strtoupper($sport), 3, $now->toDateString())
            ->shuffle()
            ->take(18);

        $props = collect(BettingRecommendationResource::collection($recommendations)->resolve())
            ->map(function (array $recommendation) use ($sport) {
                return [
                    'sport' => strtoupper($sport),
                    'player_name' => $recommendation['player']['name'] ?? 'Unknown Player',
                    'market' => $recommendation['market'] ?? 'Prop',
                    'recommendation' => $recommendation['recommendation'] ?? '',
                    'line' => $recommendation['line'] ?? null,
                    'odds' => $recommendation['odds'] ?? null,
                    'confidence' => $recommendation['confidence'] ?? null,
                    'edge' => $recommendation['edge'] ?? null,
                    'matchup' => trim(($recommendation['game']['away_team'] ?? 'Away').' @ '.($recommendation['game']['home_team'] ?? 'Home')),
                    'game_time' => isset($recommendation['game']['date'])
                        ? Carbon::parse((string) $recommendation['game']['date'])->format('M j, g:i A')
                        : null,
                    'url' => route("{$sport}.player-props", [
                        'date' => isset($recommendation['game']['date'])
                            ? Carbon::parse((string) $recommendation['game']['date'])->toDateString()
                            : null,
                        'game' => $recommendation['game']['id'] ?? null,
                    ]),
                ];
            })
            ->all();

        return $this->playerPropPoolCache[$cacheKey] = $props;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function transformPrediction(string $sport, Model $prediction): ?array
    {
        $game = $prediction->game;

        if (! $game || ! $game->homeTeam || ! $game->awayTeam) {
            return null;
        }

        $awayTeam = (string) ($game->awayTeam->abbreviation ?? $game->awayTeam->school ?? $game->awayTeam->name ?? 'Away');
        $homeTeam = (string) ($game->homeTeam->abbreviation ?? $game->homeTeam->school ?? $game->homeTeam->name ?? 'Home');
        $winProbability = (float) ($prediction->win_probability ?? 0);
        $awayWinProbability = $winProbability;
        $homeWinProbability = max(0, min(1, 1 - $awayWinProbability));
        $pickSide = $awayWinProbability >= 0.5 ? $awayTeam : $homeTeam;
        $pickConfidence = $this->displayConfidence($prediction, $awayWinProbability, $homeWinProbability);
        $spread = $prediction->predicted_spread !== null ? (float) $prediction->predicted_spread : null;
        $scheduledAt = $this->scheduledAt($game);

        return [
            'sport' => strtoupper($sport),
            'matchup' => "{$awayTeam} @ {$homeTeam}",
            'pick' => "{$pickSide} moneyline",
            'confidence' => $pickConfidence,
            'predicted_spread' => $spread,
            'predicted_total' => $prediction->predicted_total !== null ? (float) $prediction->predicted_total : null,
            'game_time' => $scheduledAt?->format('M j, g:i A'),
            'url' => url("/{$sport}/games/{$game->id}"),
        ];
    }

    private function displayConfidence(Model $prediction, float $awayWinProbability, float $homeWinProbability): float
    {
        $storedConfidence = (float) ($prediction->confidence_score ?? 0);
        $derivedConfidence = max($awayWinProbability, $homeWinProbability) * 100;

        $confidence = $storedConfidence > 0 ? $storedConfidence : $derivedConfidence;

        return round(max(self::DIGEST_CONFIDENCE_FLOOR, min(self::DIGEST_CONFIDENCE_CAP, $confidence)), 1);
    }

    private function scheduledAt(Model $game): ?CarbonInterface
    {
        $gameDate = $game->game_date;

        if (! $gameDate instanceof CarbonInterface) {
            return null;
        }

        $scheduledAt = $gameDate->copy();
        $gameTime = $game->game_time;

        if (is_string($gameTime) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $gameTime) === 1) {
            [$hours, $minutes, $seconds] = array_map('intval', explode(':', $gameTime));
            $scheduledAt = $scheduledAt->setTime($hours, $minutes, $seconds);
        }

        return $scheduledAt;
    }

    /**
     * @param  array<int, array<string, mixed>>  $predictions
     * @param  array<int, array<string, mixed>>  $playerProps
     * @param  array<int, string>  $sports
     * @return array{headline:string,intro:string,highlights:array<int,string>}
     */
    private function buildDigestSummaryFallback(array $predictions, array $playerProps, array $sports): array
    {
        $sportsLabel = collect($sports)
            ->map(fn (string $sport) => strtoupper($sport))
            ->unique()
            ->values()
            ->implode(', ');

        $highlights = [];

        if ($predictions !== []) {
            $topPrediction = $predictions[0];
            $highlights[] = sprintf(
                '%s %s leans %s at %s confidence.',
                $topPrediction['sport'],
                $topPrediction['matchup'],
                $topPrediction['pick'],
                number_format((float) $topPrediction['confidence'], 1).'%'
            );
        }

        if ($playerProps !== []) {
            $topProp = $playerProps[0];
            $highlights[] = sprintf(
                '%s: %s %s for %s.',
                $topProp['sport'],
                $topProp['player_name'],
                $topProp['recommendation'],
                $topProp['matchup']
            );
        }

        if (count($predictions) > 1) {
            $secondPrediction = $predictions[1];
            $highlights[] = sprintf(
                'Another board to watch: %s %s with projected total %s.',
                $secondPrediction['sport'],
                $secondPrediction['matchup'],
                $secondPrediction['predicted_total'] !== null
                    ? number_format((float) $secondPrediction['predicted_total'], 1)
                    : 'N/A'
            );
        }

        return [
            'headline' => $sportsLabel !== '' ? "{$sportsLabel} Daily Digest" : 'Daily Picks Digest',
            'intro' => sprintf(
                'Here is a quick scan of today\'s strongest model-driven spots%s, with a mix of game picks and player props where available.',
                $sportsLabel !== '' ? " across {$sportsLabel}" : ''
            ),
            'highlights' => array_values(array_slice($highlights, 0, 3)),
        ];
    }
}
