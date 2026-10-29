<?php

namespace App\Services;

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Http\Resources\BettingRecommendationResource;
use App\Mail\DailyPredictionsDigestMail;
use App\Models\DailyDigestSend;
use App\Models\User;
use App\Services\AI\SportsAiContentService;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\Predictions\PredictionDeliverySource;
use App\Support\SportCatalog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DailyDigestService
{
    private const SPORT_ORDER = ['nba', 'nfl', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'];

    private const PLAYER_PROP_SPORTS = ['nba', 'nfl', 'cbb', 'mlb', 'wnba'];

    private const DIGEST_CONFIDENCE_FLOOR = 50.0;

    private const DIGEST_CONFIDENCE_CAP = 95.0;

    private const DIGEST_MODEL_LEAN_CONFIDENCE_CAP = 85.0;

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $predictionPoolCache = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $playerPropPoolCache = [];

    public function __construct(
        private readonly GameBettingRecommendationService $gameBettingRecommendationService,
        private readonly PlayerPropAnalyzer $playerPropAnalyzer,
        private readonly SportsAiContentService $sportsAiContentService,
        private readonly PredictionDeliverySource $predictions,
    ) {}

    public function sendDueDigests(?CarbonInterface $now = null): int
    {
        $now ??= now();

        if (! config('subscriptions.features.email_alerts_enabled', true) || ! config('alerts.daily_digest.enabled', true)) {
            return 0;
        }

        if (! $this->mailTransportCanSend()) {
            Log::error('Daily digest emails skipped because mail transport is not configured for production delivery.', [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
            ]);

            return 0;
        }

        $sent = 0;

        $users = User::query()
            ->with('alertPreference')
            ->whereNotNull('email')
            ->get();

        $dueUsers = $users
            ->filter(fn (User $user): bool => $this->isDueForDigest($user, $now))
            ->values();

        if ($dueUsers->isEmpty()) {
            return 0;
        }

        $payload = $this->buildDigestForUser($dueUsers->first(), $now);

        if ($payload === null) {
            return 0;
        }

        foreach ($dueUsers as $user) {
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

    private function mailTransportCanSend(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        $mailer = (string) config('mail.default');

        if ($mailer !== 'smtp') {
            return ! in_array($mailer, ['log', 'array'], true);
        }

        $host = strtolower(trim((string) config('mail.mailers.smtp.host')));

        return ! in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0'], true);
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
        $targetAt = $now->copy()->setTimeFromTimeString($targetTime);

        if ($now->lessThan($targetAt)) {
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
            ->sort(function (array $a, array $b): int {
                $sportCompare = $this->digestSportRank((string) ($a['sport'] ?? ''))
                    <=> $this->digestSportRank((string) ($b['sport'] ?? ''));

                if ($sportCompare !== 0) {
                    return $sportCompare;
                }

                $edgeCompare = ((float) ($b['edge'] ?? 0)) <=> ((float) ($a['edge'] ?? 0));

                if ($edgeCompare !== 0) {
                    return $edgeCompare;
                }

                $confidenceCompare = ((float) ($b['confidence'] ?? 0)) <=> ((float) ($a['confidence'] ?? 0));

                if ($confidenceCompare !== 0) {
                    return $confidenceCompare;
                }

                return strcmp((string) ($a['matchup'] ?? ''), (string) ($b['matchup'] ?? ''));
            })
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
        $configuredSports = config('alerts.daily_digest.sports', self::SPORT_ORDER);

        return collect(is_array($configuredSports) ? $configuredSports : [])
            ->map(fn (mixed $sport) => strtolower((string) $sport))
            ->filter(fn (string $sport) => in_array($sport, SportCatalog::ALL, true))
            ->unique()
            ->values();
    }

    private function digestSportRank(string $sport): int
    {
        $configuredPriority = config('alerts.daily_digest.sport_priority', []);
        $priority = collect(is_array($configuredPriority) ? $configuredPriority : [])
            ->map(fn (mixed $value) => strtoupper((string) $value))
            ->values()
            ->flip();

        return (int) ($priority[strtoupper($sport)] ?? 999);
    }

    private function canReceiveDigestEmails(User $user): bool
    {
        return is_string($user->email) && trim($user->email) !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function predictionPoolForSport(string $sport, CarbonInterface $now): array
    {
        $cacheKey = $sport.'|'.$now->toDateString().'|'.(int) $this->predictions->usesCanonical($sport);

        if (array_key_exists($cacheKey, $this->predictionPoolCache)) {
            return $this->predictionPoolCache[$cacheKey];
        }

        $predictions = $this->predictions->forDigest($sport, $now)
            ->map(fn (PredictionDeliveryContext $prediction) => $this->transformPrediction($sport, $prediction))
            ->filter()
            ->sort(function (array $a, array $b): int {
                $edgeCompare = ((float) ($b['edge'] ?? 0)) <=> ((float) ($a['edge'] ?? 0));

                if ($edgeCompare !== 0) {
                    return $edgeCompare;
                }

                return ((float) ($b['confidence'] ?? 0)) <=> ((float) ($a['confidence'] ?? 0));
            })
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

        if (! in_array($sport, self::PLAYER_PROP_SPORTS, true)) {
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
    private function transformPrediction(string $sport, PredictionDeliveryContext $prediction): ?array
    {
        $game = $prediction->game;

        if (! $game || ! $game->homeTeam || ! $game->awayTeam
            || ($prediction->isCanonical() && $prediction->homeWinProbability === null)) {
            return null;
        }

        $awayTeam = (string) ($game->awayTeam->abbreviation ?? $game->awayTeam->school ?? $game->awayTeam->name ?? 'Away');
        $homeTeam = (string) ($game->homeTeam->abbreviation ?? $game->homeTeam->school ?? $game->homeTeam->name ?? 'Home');
        $homeWinProbability = max(0, min(1, (float) ($prediction->homeWinProbability ?? 0)));
        $awayWinProbability = max(0, min(1, 1 - $homeWinProbability));
        $topRecommendation = $this->topRecommendation($prediction)
            ?? $this->modelLeanRecommendation($awayTeam, $homeTeam, $awayWinProbability, $homeWinProbability);

        $pickConfidence = $this->displayConfidence($prediction, $topRecommendation, $awayWinProbability, $homeWinProbability);
        $spread = $prediction->predictedSpread !== null ? (float) $prediction->predictedSpread : null;
        $scheduledAt = $this->scheduledAt($game);
        $pickType = (string) ($topRecommendation['type'] ?? 'pick');
        $betLabel = (string) ($topRecommendation['bet_label'] ?? $this->cleanPickLabel((string) ($topRecommendation['recommendation'] ?? 'Model lean')));

        return [
            'sport' => strtoupper($sport),
            'matchup' => "{$awayTeam} @ {$homeTeam}",
            'pick' => (string) ($topRecommendation['recommendation'] ?? 'No recommendation available'),
            'bet_label' => $betLabel,
            'classification' => $pickType === 'model_lean' ? 'Watchlist' : 'Bettable edge',
            'market_note' => $pickType === 'model_lean'
                ? ($prediction->isCanonical()
                    ? 'No approved public betting decision for this forecast.'
                    : 'Waiting on market odds before bet classification.')
                : 'Market edge detected by the betting-value layer.',
            'confidence' => $pickConfidence,
            'edge' => round((float) ($topRecommendation['edge'] ?? 0), 1),
            'pick_type' => $pickType,
            'predicted_spread' => $spread,
            'predicted_total' => $prediction->predictedTotal !== null ? (float) $prediction->predictedTotal : null,
            'game_time' => $scheduledAt?->format('M j, g:i A'),
            'url' => url("/{$sport}/games/{$game->id}"),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function topRecommendation(PredictionDeliveryContext $prediction): ?array
    {
        return collect($this->gameBettingRecommendationService->forPrediction($prediction))
            ->filter(fn (array $recommendation) => is_numeric($recommendation['edge'] ?? null))
            ->filter(fn (array $recommendation) => trim((string) ($recommendation['recommendation'] ?? '')) !== '')
            ->filter(fn (array $recommendation) => (float) $recommendation['edge'] > 0)
            ->sort(function (array $a, array $b): int {
                $edgeCompare = ((float) ($b['edge'] ?? 0)) <=> ((float) ($a['edge'] ?? 0));

                if ($edgeCompare !== 0) {
                    return $edgeCompare;
                }

                return ((float) ($b['confidence'] ?? 0)) <=> ((float) ($a['confidence'] ?? 0));
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function modelLeanRecommendation(
        string $awayTeam,
        string $homeTeam,
        float $awayWinProbability,
        float $homeWinProbability,
    ): array {
        $leanTeam = $awayWinProbability >= $homeWinProbability ? $awayTeam : $homeTeam;
        $leanProbability = max($awayWinProbability, $homeWinProbability);
        $confidence = min(self::DIGEST_MODEL_LEAN_CONFIDENCE_CAP, $leanProbability * 100);

        return [
            'type' => 'model_lean',
            'recommendation' => "Model lean: {$leanTeam} moneyline",
            'bet_label' => "{$leanTeam} ML",
            'confidence' => round($confidence, 1),
            'edge' => 0.0,
        ];
    }

    private function cleanPickLabel(string $pick): string
    {
        return trim(str_replace(['Model lean:', 'Bet '], '', $pick));
    }

    /**
     * @param  array<string, mixed>  $recommendation
     */
    private function displayConfidence(PredictionDeliveryContext $prediction, array $recommendation, float $awayWinProbability, float $homeWinProbability): float
    {
        $storedConfidence = (float) ($recommendation['confidence'] ?? 0);

        if ($storedConfidence <= 0) {
            $storedConfidence = (float) ($prediction->confidenceScore ?? 0);
        }

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
        $officialBets = collect($predictions)
            ->filter(fn (array $prediction) => ($prediction['pick_type'] ?? null) !== 'model_lean')
            ->count();
        $modelLeans = max(0, count($predictions) - $officialBets);

        if ($predictions !== []) {
            $topPrediction = $predictions[0];
            $highlights[] = sprintf(
                'Top watch: %s %s, %s confidence.',
                $topPrediction['sport'],
                $topPrediction['bet_label'] ?? $topPrediction['pick'],
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
                'Next up: %s %s with projected total %s.',
                $secondPrediction['sport'],
                $secondPrediction['matchup'],
                $secondPrediction['predicted_total'] !== null
                    ? number_format((float) $secondPrediction['predicted_total'], 1)
                    : 'N/A'
            );
        }

        return [
            'headline' => $predictions !== []
                ? sprintf('Today\'s %s Picks', $predictions[0]['sport'])
                : ($sportsLabel !== '' ? "{$sportsLabel} Daily Digest" : 'Daily Picks Digest'),
            'intro' => sprintf(
                '%s official %s and %s watchlist %s today.',
                $officialBets,
                $officialBets === 1 ? 'bet' : 'bets',
                $modelLeans,
                $modelLeans === 1 ? 'lean' : 'leans'
            ),
            'highlights' => array_values(array_slice($highlights, 0, 3)),
        ];
    }
}
