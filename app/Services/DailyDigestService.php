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
use App\Services\BettingRecommendations\RecommendationDisplay;
use App\Services\Predictions\PredictionDeliverySource;
use App\Support\SportCatalog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

class DailyDigestService
{
    private const SPORT_ORDER = ['nba', 'nfl', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'];

    private const PLAYER_PROP_SPORTS = ['nba', 'nfl', 'cbb', 'mlb', 'wnba'];

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
        private readonly NotificationDeliveryService $deliveries,
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
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('daily_digest_sends')
                ->whereColumn('daily_digest_sends.user_id', 'users.id')
                ->whereDate('digest_date', $now->toDateString()))
            ->lazyById(100);
        $payload = null;

        foreach ($users as $user) {
            if (! $this->isDueForDigest($user, $now, checkHistory: false)) {
                continue;
            }
            $payload ??= $this->buildDigestForUser($user, $now);
            if ($payload === null) {
                return 0;
            }
            $delivery = $this->deliveries->record('daily_digest', hash('sha256', strtolower(trim($user->email))), 'mail', 'digest:'.$user->id.':'.$now->toDateString());
            if (! $delivery->wasRecentlyCreated) {
                continue;
            }
            try {
                $mail = new DailyPredictionsDigestMail(
                    user: $user,
                    summary: $payload['summary'],
                    predictions: $payload['predictions'],
                    playerProps: $payload['player_props'],
                );
                $mail->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addTextHeader('X-PickSports-Delivery-Key', $delivery->deduplication_key));
                $this->deliveries->markDispatched($delivery);
                if (! $this->deliveries->claim($delivery)) {
                    continue;
                }
                Mail::to($user)->send($mail);
                $this->deliveries->markAccepted($delivery);

                DailyDigestSend::create([
                    'user_id' => $user->id,
                    'digest_date' => $now->toDateString(),
                    'sent_at' => $now,
                    'predictions_count' => count($payload['predictions']),
                    'player_props_count' => count($payload['player_props']),
                ]);

                $sent++;
            } catch (\Throwable $exception) {
                $this->deliveries->markFailed($delivery, 'transport_outcome_unknown');
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

    public function isDueForDigest(User $user, CarbonInterface $now, bool $checkHistory = true): bool
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

        return ! $checkHistory || ! DailyDigestSend::query()
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

                $scoreCompare = ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0));

                if ($scoreCompare !== 0) {
                    return $scoreCompare;
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
            ->sortByDesc('confidence')
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
                $scoreCompare = ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0));

                if ($scoreCompare !== 0) {
                    return $scoreCompare;
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
            ->precomputedRecommendations(strtoupper($sport), $now->toDateString())
            ->filter(function (array $recommendation) use ($now, $sport): bool {
                $prop = $recommendation['prop'];
                $game = $recommendation['game'];
                $kickoff = RecommendationDisplay::kickoff($game);
                $hours = min(36, (int) config("validation.thresholds.player_prop_freshness.stale_after_hours_by_sport.{$sport}", 12));

                return in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'scheduled'], true)
                    && $kickoff !== null && $kickoff->greaterThan($now)
                    && $prop->graded_at === null
                    && $prop->fetched_at !== null
                    && $prop->fetched_at->betweenIncluded($now->copy()->subHours($hours), $now);
            })
            ->take(18);

        $props = $recommendations->map(fn (array $recommendation): array => [
            ...(new BettingRecommendationResource($recommendation))->resolve(),
            'game_model' => $recommendation['game'],
        ])
            ->map(function (array $recommendation) use ($sport) {
                return [
                    'sport' => strtoupper($sport),
                    'player_name' => $recommendation['player']['name'] ?? 'Unknown Player',
                    'market' => $recommendation['market'] ?? 'Prop',
                    'recommendation' => $recommendation['recommendation'] ?? '',
                    'line' => $recommendation['line'] ?? null,
                    'odds' => $recommendation['odds'] ?? null,
                    'confidence' => $recommendation['confidence'] ?? null,
                    'edge' => $recommendation['edge_probability'] ?? null,
                    'edge_unit' => 'percentage_points',
                    'edge_display' => RecommendationDisplay::edge(['edge' => $recommendation['edge_probability'] ?? null, 'edge_unit' => 'percentage_points']),
                    'price_display' => RecommendationDisplay::price($recommendation['odds'] ?? null),
                    'bookmaker' => $recommendation['bookmaker'] ?? null,
                    'odds_updated_at' => $recommendation['fetched_at'] ?? null,
                    'matchup' => trim(($recommendation['game']['away_team'] ?? 'Away').' @ '.($recommendation['game']['home_team'] ?? 'Home')),
                    'game_time' => $recommendation['game']['kickoff_label'] ?? RecommendationDisplay::kickoff($recommendation['game_model'])->format('M j, g:i A T'),
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
        $topRecommendation = $this->topRecommendation($prediction);

        if ($topRecommendation === null) {
            if ($prediction->homeWinProbability === null) {
                return null;
            }

            $topRecommendation = $this->modelLeanRecommendation($awayTeam, $homeTeam, $awayWinProbability, $homeWinProbability);
        }

        $pickConfidence = $this->displayConfidence($prediction, $topRecommendation);
        $spread = $prediction->predictedSpread !== null ? (float) $prediction->predictedSpread : null;
        $scheduledAt = RecommendationDisplay::kickoff($game, $prediction->isCanonical() ? $prediction->source->sportEvent?->starts_at : null);
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
            'confidence_label' => $pickType === 'model_lean' ? 'Model win probability' : 'Confidence score',
            'edge_unit' => RecommendationDisplay::edgeUnit($topRecommendation),
            'edge_display' => RecommendationDisplay::edge($topRecommendation),
            'edge_label' => $topRecommendation['edge_label'] ?? 'Model/market difference',
            'score' => $topRecommendation['score'] ?? null,
            'odds' => $topRecommendation['odds'] ?? null,
            'price_display' => RecommendationDisplay::price($topRecommendation['odds'] ?? null),
            'line' => $topRecommendation['line'] ?? ($pickType === 'total' ? ($topRecommendation['market_line'] ?? null) : null),
            'bookmaker' => $topRecommendation['bookmaker'] ?? null,
            'odds_updated_at' => $topRecommendation['odds_updated_at'] ?? null,
            'bet_decision_id' => $topRecommendation['bet_decision_id'] ?? null,
            'decision_status' => $topRecommendation['decision_status'] ?? null,
            'edge' => round((float) ($topRecommendation['edge'] ?? 0), 1),
            'pick_type' => $pickType,
            'predicted_spread' => $spread,
            'predicted_total' => $prediction->predictedTotal !== null ? (float) $prediction->predictedTotal : null,
            'game_time' => $scheduledAt?->format('M j, g:i A T') ?? 'Time TBD',
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
                $scoreCompare = ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0));

                if ($scoreCompare !== 0) {
                    return $scoreCompare;
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
        $confidence = $leanProbability * 100;

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
    private function displayConfidence(PredictionDeliveryContext $prediction, array $recommendation): ?float
    {
        if (is_numeric($recommendation['confidence'] ?? null)) {
            return round((float) $recommendation['confidence'], 1);
        }

        return ! $prediction->isCanonical() && is_numeric($prediction->confidenceScore)
            ? round((float) $prediction->confidenceScore, 1)
            : null;
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
                'Top watch: %s %s, %s: %s.',
                $topPrediction['sport'],
                $topPrediction['bet_label'] ?? $topPrediction['pick'],
                $topPrediction['confidence_label'] ?? 'Confidence score',
                is_numeric($topPrediction['confidence'] ?? null)
                    ? number_format((float) $topPrediction['confidence'], 1).(($topPrediction['pick_type'] ?? null) === 'model_lean' ? '%' : '')
                    : 'Unavailable'
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
