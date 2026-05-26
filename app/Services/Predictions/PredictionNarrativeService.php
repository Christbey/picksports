<?php

namespace App\Services\Predictions;

use App\Actions\NBA\CalculateBettingValue;
use App\Actions\NBA\CalculateTeamTrends;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Services\AI\SportsAiContentService;
use App\Services\MLB\MlbBettingSignalService;
use App\Services\NBA\NbaGameContextLayerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class PredictionNarrativeService
{
    private const TEMPLATE_VERSION = 'template-v7';

    /**
     * Generate deterministic narrative text from NBA prediction data.
     *
     * @return array{
     *   summary:string,
     *   key_points:array<int, string>,
     *   risk_note:string,
     *   generated_by:string,
     *   betting_plan:array{
     *     bet_pick:string,
     *     reasoning:string
     *   }
     * }
     */
    /**
     * @param  array<string, mixed>|null  $trendSnapshot
     */
    public function forNba(
        Prediction $prediction,
        ?Game $game = null,
        bool $allowOpenAi = true,
        ?array $trendSnapshot = null
    ): array {
        $game ??= $prediction->game;
        if ($game) {
            $game->loadMissing(['homeTeam', 'awayTeam']);
        }

        $templateNarrative = $this->templateForNba($prediction, $game, $trendSnapshot);

        if (! $allowOpenAi || ! $this->shouldUseOpenAi('nba')) {
            return $templateNarrative;
        }

        $openAiNarrative = $this->generateWithOpenAiForNba($prediction, $game, $trendSnapshot);

        return $openAiNarrative ?? $templateNarrative;
    }

    /**
     * @return array{
     *   summary:string,
     *   key_points:array<int, string>,
     *   risk_note:string,
     *   generated_by:string,
     *   betting_plan:array{bet_pick:string,reasoning:string},
     *   social_caption:string|null
     * }
     */
    public function forSport(
        Model $prediction,
        ?Model $game = null,
        string $sport = 'nba',
        bool $allowOpenAi = true
    ): array {
        $sport = strtolower($sport);

        if ($sport === 'nba' && $prediction instanceof Prediction) {
            return $this->forNba($prediction, $game instanceof Game ? $game : null, $allowOpenAi);
        }

        $game ??= $prediction->game;
        if ($game) {
            $game->loadMissing(['homeTeam', 'awayTeam']);
        }

        $templateNarrative = $this->templateForSport($prediction, $game, $sport);

        if (! $allowOpenAi || ! $this->shouldUseOpenAi($sport)) {
            return $templateNarrative;
        }

        $openAiNarrative = $this->generateWithOpenAiForSport($prediction, $game, $sport);

        return $openAiNarrative ?? $templateNarrative;
    }

    public function inputHashForNba(Prediction $prediction, ?Game $game = null): string
    {
        $game ??= $prediction->game;
        if ($game) {
            $game->loadMissing(['homeTeam', 'awayTeam']);
        }

        $payload = [
            'template_version' => self::TEMPLATE_VERSION,
            'game_id' => (int) $prediction->game_id,
            'home_team' => $this->teamName($game?->homeTeam, 'Home team'),
            'away_team' => $this->teamName($game?->awayTeam, 'Away team'),
            'predicted_spread' => (float) $prediction->predicted_spread,
            'predicted_total' => (float) $prediction->predicted_total,
            'win_probability' => (float) $prediction->win_probability,
            'confidence_score' => (float) $prediction->confidence_score,
            'home_recent_form' => (float) ($prediction->home_recent_form ?? 0),
            'away_recent_form' => (float) ($prediction->away_recent_form ?? 0),
            'home_off_eff' => (float) ($prediction->home_off_eff ?? 0),
            'home_def_eff' => (float) ($prediction->home_def_eff ?? 0),
            'away_off_eff' => (float) ($prediction->away_off_eff ?? 0),
            'away_def_eff' => (float) ($prediction->away_def_eff ?? 0),
            'rest_days_home' => (int) ($prediction->rest_days_home ?? 0),
            'rest_days_away' => (int) ($prediction->rest_days_away ?? 0),
            'home_injuries_out' => (int) ($prediction->home_injuries_out ?? 0),
            'away_injuries_out' => (int) ($prediction->away_injuries_out ?? 0),
            'home_injuries_questionable' => (int) ($prediction->home_injuries_questionable ?? 0),
            'away_injuries_questionable' => (int) ($prediction->away_injuries_questionable ?? 0),
            'injury_spread_adj' => (float) ($prediction->injury_spread_adj ?? 0),
            'injury_total_adj' => (float) ($prediction->injury_total_adj ?? 0),
            'home_away_split_adj' => (float) ($prediction->home_away_split_adj ?? 0),
            'turnover_diff_adj' => (float) ($prediction->turnover_diff_adj ?? 0),
            'rebound_margin_adj' => (float) ($prediction->rebound_margin_adj ?? 0),
            'elo_spread_component' => (float) ($prediction->elo_spread_component ?? 0),
            'efficiency_spread_component' => (float) ($prediction->efficiency_spread_component ?? 0),
            'form_spread_component' => (float) ($prediction->form_spread_component ?? 0),
            'vegas_spread' => $prediction->vegas_spread !== null ? (float) $prediction->vegas_spread : null,
            'context_layer' => $game instanceof Game
                ? app(NbaGameContextLayerService::class)->analyze($game, $prediction, $this->resolveBestBet($game))
                : null,
        ];

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', serialize($payload));
        }
    }

    public function inputHashForSport(Model $prediction, ?Model $game = null, string $sport = 'nba'): string
    {
        $sport = strtolower($sport);

        if ($sport === 'nba' && $prediction instanceof Prediction) {
            return $this->inputHashForNba($prediction, $game instanceof Game ? $game : null);
        }

        $game ??= $prediction->game;
        if ($game) {
            $game->loadMissing(['homeTeam', 'awayTeam']);
        }

        $payload = $this->buildGenericNarrativeContext($prediction, $game, $sport);

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', serialize($payload));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildNbaTrendSnapshot(?Game $game): array
    {
        if (! $game || ! $game->homeTeam || ! $game->awayTeam) {
            return [];
        }

        $sampleSize = (int) config('nba.prediction.narrative.trends_sample_size', 16);
        $sampleSize = max(5, min($sampleSize, 50));
        $tier = (string) config('nba.prediction.narrative.trends_tier', 'basic');
        $beforeDate = $game->game_date?->toDateString();
        $season = $game->season ?: null;

        try {
            /** @var CalculateTeamTrends $calculator */
            $calculator = app(CalculateTeamTrends::class);
            $homeResult = $calculator->execute($game->homeTeam, $sampleSize, $season, $beforeDate, $tier);
            $awayResult = $calculator->execute($game->awayTeam, $sampleSize, $season, $beforeDate, $tier);

            $homeTrends = is_array($homeResult['trends'] ?? null) ? $homeResult['trends'] : [];
            $awayTrends = is_array($awayResult['trends'] ?? null) ? $awayResult['trends'] : [];

            return [
                'sample_size' => $sampleSize,
                'home' => $this->normalizeTrendDataset($homeTrends),
                'away' => $this->normalizeTrendDataset($awayTrends),
            ];
        } catch (Throwable $exception) {
            Log::warning('Failed to build NBA trend snapshot for narrative generation.', [
                'game_id' => $game->id ?? null,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{
     *   summary:string,
     *   key_points:array<int, string>,
     *   risk_note:string,
     *   generated_by:string,
     *   betting_plan:array{
     *     bet_pick:string,
     *     reasoning:string
     *   }
     * }
     */
    /**
     * @param  array<string, mixed>|null  $trendSnapshot
     */
    private function templateForNba(Prediction $prediction, ?Game $game = null, ?array $trendSnapshot = null): array
    {
        $homeName = $this->teamName($game?->homeTeam, 'Home team');
        $awayName = $this->teamName($game?->awayTeam, 'Away team');

        $homeWinProb = (float) $prediction->win_probability;
        $awayWinProb = 1 - $homeWinProb;
        $confidence = (float) $prediction->confidence_score;
        $spread = (float) $prediction->predicted_spread;
        $total = (float) $prediction->predicted_total;
        $homeRecentForm = (float) ($prediction->home_recent_form ?? 0);
        $awayRecentForm = (float) ($prediction->away_recent_form ?? 0);
        $homeOffEff = (float) ($prediction->home_off_eff ?? 0);
        $homeDefEff = (float) ($prediction->home_def_eff ?? 0);
        $awayOffEff = (float) ($prediction->away_off_eff ?? 0);
        $awayDefEff = (float) ($prediction->away_def_eff ?? 0);
        $homeRest = (int) ($prediction->rest_days_home ?? 0);
        $awayRest = (int) ($prediction->rest_days_away ?? 0);
        $homeOut = (int) ($prediction->home_injuries_out ?? 0);
        $awayOut = (int) ($prediction->away_injuries_out ?? 0);
        $homeQuestionable = (int) ($prediction->home_injuries_questionable ?? 0);
        $awayQuestionable = (int) ($prediction->away_injuries_questionable ?? 0);
        $injurySpreadAdj = (float) ($prediction->injury_spread_adj ?? 0);
        $vegasSpread = $prediction->vegas_spread !== null ? (float) $prediction->vegas_spread : null;
        $eloComponent = (float) ($prediction->elo_spread_component ?? 0);
        $efficiencyComponent = (float) ($prediction->efficiency_spread_component ?? 0);
        $formComponent = (float) ($prediction->form_spread_component ?? 0);
        $turnoverAdj = (float) ($prediction->turnover_diff_adj ?? 0);
        $reboundAdj = (float) ($prediction->rebound_margin_adj ?? 0);
        $homeAwayAdj = (float) ($prediction->home_away_split_adj ?? 0);
        $depthChartNote = $this->depthChartNarrativeNote($prediction);

        $pickHome = $homeWinProb >= $awayWinProb;
        $pickedTeam = $pickHome ? $homeName : $awayName;
        $otherTeam = $pickHome ? $awayName : $homeName;
        $pickedProb = $pickHome ? $homeWinProb : $awayWinProb;
        $trendLeader = $homeRecentForm >= $awayRecentForm ? $homeName : $awayName;
        $trendGap = abs($homeRecentForm - $awayRecentForm);
        $homeNet = $homeOffEff - $homeDefEff;
        $awayNet = $awayOffEff - $awayDefEff;
        $netGap = $homeNet - $awayNet;
        $efficiencyLeader = $netGap >= 0 ? $homeName : $awayName;
        $efficiencyGap = abs($netGap);
        $restEdge = $homeRest - $awayRest;
        $restLeader = $restEdge > 0 ? $homeName : ($restEdge < 0 ? $awayName : null);
        // `vegas_spread` is captured in Vegas convention (home favored = negative point),
        // while `predicted_spread` is home-perspective (home favored = positive). Add, don't subtract,
        // to get "extra home margin the model sees beyond the line".
        $marketEdge = $vegasSpread !== null ? ($spread + $vegasSpread) : null;
        $modelVsMarketEdgeTeam = $marketEdge !== null
            ? (($marketEdge >= 0) ? $homeName : $awayName)
            : null;
        $bestBet = $this->resolveBestBet($game);
        $contextLayer = $game instanceof Game
            ? app(NbaGameContextLayerService::class)->analyze($game, $prediction, $bestBet)
            : [];
        $situationalAdj = $turnoverAdj + $reboundAdj + $homeAwayAdj;
        $trendSnapshot = $trendSnapshot ?? [];
        $pickedTrendData = $pickHome
            ? (is_array($trendSnapshot['home'] ?? null) ? $trendSnapshot['home'] : [])
            : (is_array($trendSnapshot['away'] ?? null) ? $trendSnapshot['away'] : []);
        $otherTrendData = $pickHome
            ? (is_array($trendSnapshot['away'] ?? null) ? $trendSnapshot['away'] : [])
            : (is_array($trendSnapshot['home'] ?? null) ? $trendSnapshot['home'] : []);
        $pickedTrendCategories = is_array($pickedTrendData['categories'] ?? null) ? $pickedTrendData['categories'] : [];
        $otherTrendCategories = is_array($otherTrendData['categories'] ?? null) ? $otherTrendData['categories'] : [];
        $pickedTrendSignals = $this->scoreTrendSignals($pickedTrendCategories);
        $otherTrendSignals = $this->scoreTrendSignals($otherTrendCategories);
        $bestPickedSignal = $this->bestTrendSignal($pickedTrendSignals, true);
        $bestPickedRiskSignal = $this->bestTrendSignal($pickedTrendSignals, false);
        $bestOtherSignal = $this->bestTrendSignal($otherTrendSignals, true);
        $pickedCategoryCount = (int) ($pickedTrendData['category_count'] ?? count($pickedTrendCategories));
        $otherCategoryCount = (int) ($otherTrendData['category_count'] ?? count($otherTrendCategories));
        $pickedMessageCount = (int) ($pickedTrendData['message_count'] ?? 0);
        $otherMessageCount = (int) ($otherTrendData['message_count'] ?? 0);

        $components = [
            'Elo rating gap' => abs($eloComponent),
            'efficiency profile' => abs($efficiencyComponent),
            'recent form trend' => abs($formComponent),
            'situational adjustments' => abs($situationalAdj),
        ];
        arsort($components);
        $dominantDriver = (string) array_key_first($components);

        $totalLean = $total >= 232
            ? 'faster, higher-scoring environment'
            : ($total <= 219 ? 'slower, lower-scoring script' : 'mid-range scoring script');

        $restSentence = $restLeader
            ? sprintf('%s also carries a rest edge (%d vs %d days).', $restLeader, max($homeRest, $awayRest), min($homeRest, $awayRest))
            : 'Rest is neutral, so model edge comes mostly from efficiency and form.';
        $injurySentence = sprintf(
            'Availability: %s %d out/%d questionable, %s %d out/%d questionable (spread impact %.1f to home).',
            $homeName,
            $homeOut,
            $homeQuestionable,
            $awayName,
            $awayOut,
            $awayQuestionable,
            $injurySpreadAdj
        );

        $trendSentence = sprintf(
            'Trend engine scanned %d categories (%d signals) for %s and %d categories (%d signals) for %s.',
            $pickedCategoryCount,
            $pickedMessageCount,
            $pickedTeam,
            $otherCategoryCount,
            $otherMessageCount,
            $otherTeam
        );
        $hasTrendSignals = ($pickedMessageCount + $otherMessageCount) > 0;
        if (is_array($bestPickedSignal)) {
            $trendSentence .= ' Best support: '.$this->cleanTrendSentence((string) $bestPickedSignal['message']).'.';
        }
        if (is_array($bestOtherSignal)) {
            $trendSentence .= ' Main counter-signal: '.$this->cleanTrendSentence((string) $bestOtherSignal['message']).'.';
        }

        $profileSentence = $this->buildProfileSentence(
            pickedTeam: $pickedTeam,
            efficiencyLeader: $efficiencyLeader,
            efficiencyGap: $efficiencyGap,
            homeTeam: $homeName,
            awayTeam: $awayName,
            homeNet: $homeNet,
            awayNet: $awayNet
        );

        $contextClassification = (string) data_get($contextLayer, 'betting_context.classification', '');
        $contextPass = in_array($contextClassification, ['pass_or_wait', 'clear_pass'], true);

        $summary = $bestBet && ! $contextPass
            ? sprintf(
                'Best bet: %s%s. Why: %s.',
                $bestBet['recommendation'],
                $bestBet['odds_text'] !== '' ? " ({$bestBet['odds_text']})" : '',
                $bestBet['reasoning']
            )
            : ($bestBet && $contextPass
            ? sprintf(
                'Pregame pass: %s has model value, but context flags conflict. %s',
                $bestBet['recommendation'],
                $this->contextConflictSummary($contextLayer)
            )
            : sprintf(
                "Tonight's lean is %s (%s win probability), with %s and total of %s. Biggest edge: %s. %s %s%s",
                $pickedTeam,
                $this->percent($pickedProb),
                $this->spreadNarrativeLead($spread, $vegasSpread, $marketEdge, $modelVsMarketEdgeTeam),
                $this->number($total),
                $dominantDriver,
                $profileSentence,
                $restSentence,
                $injurySentence.($hasTrendSignals ? ' '.$trendSentence : '')
            ));

        $keyPoints = [
            $bestBet && ! $contextPass
                ? sprintf(
                    'Recommended wager at Vegas: %s%s.',
                    $bestBet['recommendation'],
                    $bestBet['odds_text'] !== '' ? " ({$bestBet['odds_text']})" : ''
                )
                : ($bestBet && $contextPass
                    ? sprintf('Pregame pass: %s is downgraded by context conflict.', $bestBet['recommendation'])
                : sprintf(
                    'Win odds snapshot: %s %s vs %s %s.',
                    $homeName,
                    $this->percent($homeWinProb),
                    $awayName,
                    $this->percent($awayWinProb)
                )),
            sprintf(
                'Model win view: %s %s vs %s %s.',
                $homeName,
                $this->percent($homeWinProb),
                $awayName,
                $this->percent($awayWinProb)
            ),
            $injurySentence,
            $bestBet
                ? sprintf('Reasoning: %s.', $bestBet['reasoning'])
                : ($vegasSpread !== null && $marketEdge !== null && $modelVsMarketEdgeTeam !== null
                ? sprintf(
                    'Market edge: model %s vs Vegas %s creates %.1f points toward %s, with a %s game script from the total.',
                    $this->signedNumber($spread),
                    $this->signedNumber($vegasSpread),
                    abs($marketEdge),
                    $modelVsMarketEdgeTeam,
                    $totalLean
                )
                : sprintf(
                    'Spread angle: %s (edge to the %s side), and the total suggests a %s.',
                    $this->signedNumber($spread),
                    $pickHome ? 'home' : 'away',
                    $totalLean
                )),
            sprintf(
                'Team profile: %s net %.1f (off %.1f / def %.1f) vs %s net %.1f (off %.1f / def %.1f).',
                $homeName,
                $homeNet,
                $homeOffEff,
                $homeDefEff,
                $awayName,
                $awayNet,
                $awayOffEff,
                $awayDefEff
            ),
            sprintf(
                'Form check: %s leads recent form by %.3f, and situational adjustments add %s points.',
                $trendLeader,
                $trendGap,
                $this->signedNumber($situationalAdj)
            ),
        ];

        if ($depthChartNote !== null) {
            $keyPoints[] = $depthChartNote;
        }

        foreach ($this->contextLayerKeyPoints($contextLayer) as $contextPoint) {
            $keyPoints[] = $contextPoint;
        }

        if ($hasTrendSignals) {
            $keyPoints[] = sprintf(
                'Trend coverage: %s %d categories/%d signals, %s %d categories/%d signals (last %d games).',
                $pickedTeam,
                $pickedCategoryCount,
                $pickedMessageCount,
                $otherTeam,
                $otherCategoryCount,
                $otherMessageCount,
                (int) ($trendSnapshot['sample_size'] ?? 0)
            );
        } else {
            $keyPoints[] = 'Trend dataset is thin for this matchup, so this lean is driven mostly by the core model inputs.';
        }

        if (is_array($bestPickedSignal)) {
            $keyPoints[] = sprintf(
                'Best trend edge for %s (%s): %s.',
                $pickedTeam,
                str_replace('_', ' ', (string) $bestPickedSignal['category']),
                $this->cleanTrendSentence((string) $bestPickedSignal['message'])
            );
        }

        if (is_array($bestOtherSignal)) {
            $keyPoints[] = sprintf(
                'Strongest opposing trend for %s (%s): %s.',
                $otherTeam,
                str_replace('_', ' ', (string) $bestOtherSignal['category']),
                $this->cleanTrendSentence((string) $bestOtherSignal['message'])
            );
        }

        if (is_array($bestPickedRiskSignal)) {
            $keyPoints[] = sprintf(
                'Risk trend on %s (%s): %s.',
                $pickedTeam,
                str_replace('_', ' ', (string) $bestPickedRiskSignal['category']),
                $this->cleanTrendSentence((string) $bestPickedRiskSignal['message'])
            );
        }

        if (! $bestBet && $marketEdge !== null && $modelVsMarketEdgeTeam !== null) {
            $keyPoints[] = sprintf(
                'Market check: model spread %s vs market %s shows %.1f points of value toward %s.',
                $this->signedNumber($spread),
                $this->signedNumber($vegasSpread),
                abs($marketEdge),
                $modelVsMarketEdgeTeam
            );
        }

        $riskNote = $confidence >= 75
            ? sprintf('Risk note: confidence is %.1f, so this is a strong lean, not a lock. Late news can still flip the script.', $confidence)
            : sprintf('Risk note: confidence is %.1f, so keep stake size controlled and expect swingy outcomes.', $confidence);

        return [
            'summary' => $summary,
            'key_points' => $keyPoints,
            'risk_note' => $riskNote,
            'generated_by' => self::TEMPLATE_VERSION,
            'betting_plan' => $this->buildBettingPlan(
                bestBet: $bestBet,
                pickedTeam: $pickedTeam,
                spread: $spread,
                confidence: $confidence,
                marketEdge: $marketEdge,
                vegasSpread: $vegasSpread,
                homeTeam: $homeName,
                awayTeam: $awayName,
                homeRest: $homeRest,
                awayRest: $awayRest,
                trendLeader: $trendLeader,
                trendGap: $trendGap,
                efficiencyLeader: $efficiencyLeader,
                efficiencyGap: $efficiencyGap,
                contextLayer: $contextLayer
            ),
            'context_layer' => $contextLayer,
            'social_caption' => $this->buildSocialCaption(
                bestBet: $bestBet,
                pickedTeam: $pickedTeam,
                otherTeam: $otherTeam,
                winProb: $pickedProb,
                spread: $spread,
                total: $total,
                trendSentence: is_array($bestPickedSignal) ? (string) $bestPickedSignal['message'] : null,
                vegasSpread: $vegasSpread,
                marketEdge: $marketEdge,
                marketEdgeTeam: $modelVsMarketEdgeTeam
            ),
        ];
    }

    /**
     * @return array{
     *   summary:string,
     *   key_points:array<int, string>,
     *   risk_note:string,
     *   generated_by:string,
     *   betting_plan:array{
     *     bet_pick:string,
     *     reasoning:string
     *   }
     * }|null
     */
    /**
     * @param  array<string, mixed>|null  $trendSnapshot
     */
    private function generateWithOpenAiForNba(
        Prediction $prediction,
        ?Game $game = null,
        ?array $trendSnapshot = null
    ): ?array {
        $apiKey = (string) config('services.openai.api_key', '');
        $model = (string) config('nba.prediction.narrative.model', 'gpt-4o-mini');

        $homeName = $this->teamName($game?->homeTeam, 'Home team');
        $awayName = $this->teamName($game?->awayTeam, 'Away team');
        $homeWinProb = (float) $prediction->win_probability;
        $awayWinProb = 1 - $homeWinProb;
        $pickHome = $homeWinProb >= $awayWinProb;
        $pickedTeam = $pickHome ? $homeName : $awayName;

        try {
            $decoded = app(SportsAiContentService::class)->generatePredictionNarrative(
                $this->buildNbaPrompt(
                    $prediction,
                    $homeName,
                    $awayName,
                    $trendSnapshot,
                    $game instanceof Game ? app(NbaGameContextLayerService::class)->analyze($game, $prediction, $this->resolveBestBet($game)) : []
                ),
                provider: 'openai',
                model: $model,
            );

            if (! $decoded) {
                return null;
            }

            return [
                'summary' => $decoded['summary'],
                'key_points' => $decoded['key_points'],
                'risk_note' => $decoded['risk_note'],
                'generated_by' => $decoded['generated_by'],
                'betting_plan' => $decoded['betting_plan'] ?? $this->buildBettingPlan(
                    bestBet: $this->resolveBestBet($game),
                    pickedTeam: $pickedTeam,
                    spread: (float) $prediction->predicted_spread,
                    confidence: (float) $prediction->confidence_score,
                    marketEdge: $prediction->vegas_spread !== null
                        ? ((float) $prediction->predicted_spread + (float) $prediction->vegas_spread)
                        : null,
                    vegasSpread: $prediction->vegas_spread !== null ? (float) $prediction->vegas_spread : null,
                    homeTeam: $homeName,
                    awayTeam: $awayName,
                    homeRest: (int) ($prediction->rest_days_home ?? 0),
                    awayRest: (int) ($prediction->rest_days_away ?? 0),
                    trendLeader: ((float) ($prediction->home_recent_form ?? 0) >= (float) ($prediction->away_recent_form ?? 0)) ? $homeName : $awayName,
                    trendGap: abs((float) ($prediction->home_recent_form ?? 0) - (float) ($prediction->away_recent_form ?? 0)),
                    efficiencyLeader: (((float) ($prediction->home_off_eff ?? 0) - (float) ($prediction->home_def_eff ?? 0))
                        >= ((float) ($prediction->away_off_eff ?? 0) - (float) ($prediction->away_def_eff ?? 0))) ? $homeName : $awayName,
                    efficiencyGap: abs(
                        ((float) ($prediction->home_off_eff ?? 0) - (float) ($prediction->home_def_eff ?? 0))
                        - ((float) ($prediction->away_off_eff ?? 0) - (float) ($prediction->away_def_eff ?? 0))
                    ),
                    contextLayer: $game instanceof Game ? app(NbaGameContextLayerService::class)->analyze($game, $prediction, $this->resolveBestBet($game)) : []
                ),
                'context_layer' => $game instanceof Game ? app(NbaGameContextLayerService::class)->analyze($game, $prediction, $this->resolveBestBet($game)) : [],
                'social_caption' => $decoded['social_caption'] ?? null,
            ];
        } catch (Throwable $exception) {
            Log::warning('Prediction narrative AI request threw exception.', [
                'message' => $exception->getMessage(),
                'provider' => 'openai',
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *   summary:string,
     *   key_points:array<int,string>,
     *   risk_note:string,
     *   generated_by:string,
     *   betting_plan:array{bet_pick:string,reasoning:string},
     *   social_caption:string|null
     * }|null
     */
    private function generateWithOpenAiForSport(
        Model $prediction,
        ?Model $game,
        string $sport
    ): ?array {
        $model = (string) config('ai.features.sports_prediction_narratives.model', 'gpt-4o-mini');

        return app(SportsAiContentService::class)->generatePredictionNarrative(
            $this->buildSportPrompt($prediction, $game, $sport),
            provider: 'openai',
            model: $model,
        );
    }

    private function shouldUseOpenAi(string $sport = 'nba'): bool
    {
        $provider = $sport === 'nba'
            ? (string) config('nba.prediction.narrative.provider', 'template')
            : (string) config('ai.features.sports_prediction_narratives.provider', 'template');
        $apiKey = (string) config('services.openai.api_key', '');

        return $provider === 'openai' && $apiKey !== '';
    }

    /**
     * @return array{
     *   summary:string,
     *   key_points:array<int,string>,
     *   risk_note:string,
     *   generated_by:string,
     *   betting_plan:array{bet_pick:string,reasoning:string},
     *   social_caption:string|null
     * }
     */
    private function templateForSport(Model $prediction, ?Model $game, string $sport): array
    {
        $context = $this->buildGenericNarrativeContext($prediction, $game, $sport);
        $pickLabel = $context['picked_team'].' moneyline';
        $summary = sprintf(
            '%s lean: %s (%s win probability). Projected spread %s with total %s.',
            strtoupper($sport),
            $context['picked_team'],
            $this->percent((float) $context['picked_probability']),
            $context['predicted_spread'] !== null ? $this->signedNumber((float) $context['predicted_spread']) : 'N/A',
            $context['predicted_total'] !== null ? $this->number((float) $context['predicted_total']) : 'N/A'
        );

        $keyPoints = [
            sprintf(
                'Matchup: %s at %s.',
                $context['away_team'],
                $context['home_team']
            ),
            sprintf(
                'Model win view: %s %s vs %s %s.',
                $context['home_team'],
                $this->percent((float) $context['home_win_probability']),
                $context['away_team'],
                $this->percent((float) $context['away_win_probability'])
            ),
            sprintf(
                'Projected spread: %s. Projected total: %s.',
                $context['predicted_spread'] !== null ? $this->signedNumber((float) $context['predicted_spread']) : 'N/A',
                $context['predicted_total'] !== null ? $this->number((float) $context['predicted_total']) : 'N/A'
            ),
            sprintf(
                'Confidence score: %.1f.',
                (float) $context['confidence_score']
            ),
        ];

        if ($context['depth_chart_note'] !== null) {
            $keyPoints[] = $context['depth_chart_note'];
        }

        if ($context['home_metric'] !== null || $context['away_metric'] !== null) {
            $keyPoints[] = sprintf(
                'Rating snapshot: %s %s vs %s %s.',
                $context['home_team'],
                $context['home_metric'] ?? 'N/A',
                $context['away_team'],
                $context['away_metric'] ?? 'N/A'
            );
        }

        return [
            'summary' => $summary,
            'key_points' => $keyPoints,
            'risk_note' => sprintf(
                'Risk note: confidence is %.1f, so treat this as a lean and stay alert for lineup or market movement.',
                (float) $context['confidence_score']
            ),
            'generated_by' => 'template-generic-v1',
            'betting_plan' => $this->genericBettingPlan($prediction, $sport, $context, $pickLabel),
            'social_caption' => sprintf(
                '%s lean: %s at %s win probability.',
                strtoupper($sport),
                $context['picked_team'],
                $this->percent((float) $context['picked_probability'])
            ),
        ];
    }

    private function buildSportPrompt(Model $prediction, ?Model $game, string $sport): string
    {
        $context = $this->buildGenericNarrativeContext($prediction, $game, $sport);

        return implode("\n", array_filter([
            'Create a concise sports betting narrative from this model data.',
            'Return data that matches this exact structure: summary, key_points, risk_note, betting_plan, social_caption.',
            'betting_plan keys: bet_pick (string), reasoning (string).',
            'Do not include markdown, unsupported fields, or guarantees.',
            'Keep the tone analytical, cautious, and specific.',
            'Sport: '.strtoupper($sport),
            'Home team: '.$context['home_team'],
            'Away team: '.$context['away_team'],
            'Home win probability: '.$this->percent((float) $context['home_win_probability']),
            'Away win probability: '.$this->percent((float) $context['away_win_probability']),
            $context['predicted_spread'] !== null ? 'Predicted spread (positive favors home): '.$this->signedNumber((float) $context['predicted_spread']) : null,
            $context['predicted_total'] !== null ? 'Predicted total: '.$this->number((float) $context['predicted_total']) : null,
            'Confidence score: '.$this->number((float) $context['confidence_score']),
            $context['home_metric'] !== null ? 'Home model metric: '.$context['home_metric'] : null,
            $context['away_metric'] !== null ? 'Away model metric: '.$context['away_metric'] : null,
            $context['depth_chart_prompt_line'] ?? null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGenericNarrativeContext(Model $prediction, ?Model $game, string $sport): array
    {
        $game ??= $prediction->game;
        if ($game) {
            $game->loadMissing(['homeTeam', 'awayTeam']);
        }

        $homeTeam = $this->teamName($game?->homeTeam, 'Home team');
        $awayTeam = $this->teamName($game?->awayTeam, 'Away team');
        $homeWinProb = max(0.0, min(1.0, (float) ($prediction->win_probability ?? 0)));
        $awayWinProb = max(0.0, min(1.0, 1 - $homeWinProb));
        $pickHome = $homeWinProb >= $awayWinProb;
        $pickedTeam = $pickHome ? $homeTeam : $awayTeam;

        return [
            'template_version' => 'template-generic-v1',
            'sport' => $sport,
            'game_id' => (int) ($prediction->game_id ?? 0),
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'home_win_probability' => $homeWinProb,
            'away_win_probability' => $awayWinProb,
            'picked_team' => $pickedTeam,
            'picked_probability' => $pickHome ? $homeWinProb : $awayWinProb,
            'predicted_spread' => $prediction->predicted_spread !== null ? (float) $prediction->predicted_spread : null,
            'predicted_total' => $prediction->predicted_total !== null ? (float) $prediction->predicted_total : null,
            'confidence_score' => (float) ($prediction->confidence_score ?? 0),
            'home_metric' => $this->firstMetricValue($prediction, ['home_elo', 'home_team_elo', 'home_combined_elo', 'home_fpi', 'home_off_eff']),
            'away_metric' => $this->firstMetricValue($prediction, ['away_elo', 'away_team_elo', 'away_combined_elo', 'away_fpi', 'away_off_eff']),
            'depth_chart_note' => $this->depthChartNarrativeNote($prediction),
            'depth_chart_prompt_line' => $this->depthChartPromptLine($prediction),
            'betting_plan_context' => $this->genericBettingPlanContext($prediction, $sport),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function genericBettingPlan(Model $prediction, string $sport, array $context, string $pickLabel): array
    {
        $betFilter = is_array($context['betting_plan_context'] ?? null)
            ? $context['betting_plan_context']
            : $this->genericBettingPlanContext($prediction, $sport);

        if ($sport === 'mlb' && $betFilter !== []) {
            $classification = (string) ($betFilter['classification'] ?? 'pass');
            $type = str_replace('_', ' ', (string) ($betFilter['type'] ?? 'moneyline'));
            $team = (string) ($betFilter['team_name'] ?? $context['picked_team']);
            $marketPrice = $betFilter['market_price'] ?? null;
            $marketImplied = $betFilter['market_implied_probability'] ?? null;
            $modelProbability = $betFilter['model_probability'] ?? $context['picked_probability'];
            $noBetReason = (string) ($betFilter['no_bet_reason'] ?? '');
            $riskFlags = array_values(array_filter(array_map('strval', (array) ($betFilter['risk_flags'] ?? []))));
            $reasonCodes = array_values(array_filter(array_map('strval', (array) ($betFilter['reason_codes'] ?? []))));

            if ($classification === 'pass') {
                return [
                    'bet_pick' => 'No bet / pass '.$type.'.',
                    'reasoning' => $this->mlbPassReasoning($team, $modelProbability, $marketPrice, $marketImplied, $noBetReason),
                    'classification' => 'pass',
                    'against_bet' => $this->mlbAgainstBetReasons($riskFlags, $noBetReason),
                    'pass_reasons' => array_values(array_filter([$noBetReason])),
                    'reason_codes' => $reasonCodes,
                ];
            }

            return [
                'bet_pick' => 'Bet '.$team.' '.$type.'.',
                'reasoning' => $this->mlbPlayableReasoning($team, $modelProbability, $marketPrice, $marketImplied),
                'classification' => $classification,
                'for_bet' => array_slice($reasonCodes, 0, 5),
                'against_bet' => $riskFlags,
                'reason_codes' => $reasonCodes,
            ];
        }

        return [
            'bet_pick' => 'Bet '.$pickLabel.'.',
            'reasoning' => sprintf(
                'The model gives %s the higher win probability at %s.',
                $context['picked_team'],
                $this->percent((float) $context['picked_probability'])
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function genericBettingPlanContext(Model $prediction, string $sport): array
    {
        if ($sport !== 'mlb' || ! $prediction instanceof MlbPrediction) {
            return [];
        }

        $prediction->loadMissing(['game.homeTeam', 'game.awayTeam']);
        $candidates = app(MlbBettingSignalService::class)->betCandidatesForPrediction($prediction, true, true);

        if ($candidates === []) {
            return [];
        }

        usort($candidates, fn (array $left, array $right): int => ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0)));

        return $candidates[0];
    }

    private function mlbPassReasoning(string $team, mixed $modelProbability, mixed $marketPrice, mixed $marketImplied, string $noBetReason): string
    {
        $parts = [
            sprintf('The model leans %s at %s.', $team, $this->percent((float) $modelProbability)),
        ];

        if (is_numeric($marketPrice) && is_numeric($marketImplied)) {
            $parts[] = sprintf(
                'The current moneyline %s implies %s, so the price is ahead of the model.',
                $this->formatOddsText($marketPrice),
                $this->percent((float) $marketImplied)
            );
        }

        if ($noBetReason !== '') {
            $parts[] = 'Pass reason: '.str_replace('_', ' ', $noBetReason).'.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<int, string>  $riskFlags
     * @return array<int, string>
     */
    private function mlbAgainstBetReasons(array $riskFlags, string $noBetReason): array
    {
        return array_values(array_unique(array_filter([
            $noBetReason !== '' ? str_replace('_', ' ', $noBetReason) : null,
            ...array_map(fn (string $flag): string => str_replace('_', ' ', $flag), $riskFlags),
        ])));
    }

    private function mlbPlayableReasoning(string $team, mixed $modelProbability, mixed $marketPrice, mixed $marketImplied): string
    {
        $reasoning = sprintf('The MLB bet filter keeps %s playable at %s model probability.', $team, $this->percent((float) $modelProbability));

        if (is_numeric($marketPrice) && is_numeric($marketImplied)) {
            $reasoning .= sprintf(
                ' Market price %s implies %s.',
                $this->formatOddsText($marketPrice),
                $this->percent((float) $marketImplied)
            );
        }

        return $reasoning;
    }

    private function depthChartNarrativeNote(Model $prediction): ?string
    {
        $metadata = is_array($prediction->model_metadata ?? null) ? $prediction->model_metadata : [];

        if (is_array($metadata['depth_chart_injuries'] ?? null)) {
            $injuries = $metadata['depth_chart_injuries'];

            return sprintf(
                'Depth-chart weighting: home %.2f out / %.2f questionable, away %.2f out / %.2f questionable, shifting spread %s.',
                (float) ($injuries['home_out_weighted'] ?? 0.0),
                (float) ($injuries['home_questionable_weighted'] ?? 0.0),
                (float) ($injuries['away_out_weighted'] ?? 0.0),
                (float) ($injuries['away_questionable_weighted'] ?? 0.0),
                $this->signedNumber((float) ($injuries['spread_adjustment'] ?? 0.0))
            );
        }

        if (is_array($metadata['depth_chart_context'] ?? null)) {
            $context = $metadata['depth_chart_context'];
            $homeSource = (string) ($context['home_pitcher_source'] ?? 'unknown');
            $awaySource = (string) ($context['away_pitcher_source'] ?? 'unknown');

            return sprintf(
                'Depth-chart starter context: home pitcher source %s, away pitcher source %s.',
                str_replace('_', ' ', $homeSource),
                str_replace('_', ' ', $awaySource)
            );
        }

        return null;
    }

    private function depthChartPromptLine(Model $prediction): ?string
    {
        $note = $this->depthChartNarrativeNote($prediction);

        return $note !== null ? 'Depth chart context: '.$note : null;
    }

    private function firstMetricValue(Model $prediction, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (isset($prediction->{$field}) && $prediction->{$field} !== null) {
                return $field.': '.$this->number((float) $prediction->{$field});
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $trendSnapshot
     */
    private function buildNbaPrompt(
        Prediction $prediction,
        string $homeName,
        string $awayName,
        ?array $trendSnapshot = null,
        array $contextLayer = []
    ): string {
        $homeWinProb = (float) $prediction->win_probability;
        $awayWinProb = 1 - $homeWinProb;
        $homeRecentForm = (float) ($prediction->home_recent_form ?? 0);
        $awayRecentForm = (float) ($prediction->away_recent_form ?? 0);
        $homeOffEff = (float) ($prediction->home_off_eff ?? 0);
        $homeDefEff = (float) ($prediction->home_def_eff ?? 0);
        $awayOffEff = (float) ($prediction->away_off_eff ?? 0);
        $awayDefEff = (float) ($prediction->away_def_eff ?? 0);

        $trendLines = [];
        if (is_array($trendSnapshot)) {
            foreach ([['home', $homeName], ['away', $awayName]] as [$side, $teamName]) {
                $trendData = is_array($trendSnapshot[$side] ?? null) ? $trendSnapshot[$side] : [];
                $categories = is_array($trendData['categories'] ?? null) ? $trendData['categories'] : [];

                foreach ($categories as $category => $messages) {
                    if (! is_array($messages)) {
                        continue;
                    }
                    foreach ($messages as $message) {
                        $clean = trim((string) $message);
                        if ($clean === '') {
                            continue;
                        }
                        $trendLines[] = sprintf(
                            '%s %s trend: %s',
                            $teamName,
                            str_replace('_', ' ', (string) $category),
                            $this->cleanTrendSentence($clean)
                        );
                    }
                }
            }
        }

        $contextLines = $this->contextLayerPromptLines($contextLayer);

        return implode("\n", [
            'Create a concise NBA prediction narrative from this model data.',
            'Return data that matches this exact structure: summary, key_points, risk_note, betting_plan, social_caption.',
            'betting_plan keys: bet_pick (string), reasoning (string).',
            'Do not include markdown, hedging disclaimers beyond the risk note, or unsupported fields.',
            'Include one key point on team stats comparison and one on team trend direction.',
            'Write in plain, punchy language for social sharing. Keep lines short and specific.',
            'Use betting_plan for the exact wager recommendation and the reason behind it.',
            'Do not include numeric line values in betting_plan.bet_pick (example: "Bet Knicks to cover").',
            "Home team: {$homeName}",
            "Away team: {$awayName}",
            'Home win probability: '.$this->percent($homeWinProb),
            'Away win probability: '.$this->percent($awayWinProb),
            'Predicted spread (positive favors home): '.$this->signedNumber((float) $prediction->predicted_spread),
            'Predicted total: '.$this->number((float) $prediction->predicted_total),
            'Home offense rating: '.$this->number($homeOffEff),
            'Home defense rating: '.$this->number($homeDefEff),
            'Away offense rating: '.$this->number($awayOffEff),
            'Away defense rating: '.$this->number($awayDefEff),
            'Home recent-form rating: '.number_format($homeRecentForm, 3),
            'Away recent-form rating: '.number_format($awayRecentForm, 3),
            'Confidence score: '.$this->number((float) $prediction->confidence_score),
            $this->depthChartPromptLine($prediction),
            ...$trendLines,
            ...$contextLines,
            'Tone: analytical, cautious, no guarantees.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $contextLayer
     * @return array<int, string>
     */
    private function contextLayerKeyPoints(array $contextLayer): array
    {
        if ($contextLayer === []) {
            return [];
        }

        $points = [];
        $series = is_array($contextLayer['series_total_trend'] ?? null) ? $contextLayer['series_total_trend'] : [];
        $otAdjusted = is_array($contextLayer['overtime_adjusted_total'] ?? null) ? $contextLayer['overtime_adjusted_total'] : [];
        $nonOt = is_array($contextLayer['non_ot_series_average'] ?? null) ? $contextLayer['non_ot_series_average'] : [];
        $spikes = is_array($contextLayer['quarter_scoring_spikes'] ?? null) ? $contextLayer['quarter_scoring_spikes'] : [];
        $fouls = is_array($contextLayer['playoff_foul_late_game_risk'] ?? null) ? $contextLayer['playoff_foul_late_game_risk'] : [];
        $injuries = is_array($contextLayer['injury_impact'] ?? null) ? $contextLayer['injury_impact'] : [];
        $market = is_array($contextLayer['market_movement'] ?? null) ? $contextLayer['market_movement'] : [];
        $conflict = is_array($contextLayer['model_vs_series_conflict'] ?? null) ? $contextLayer['model_vs_series_conflict'] : [];
        $historical = is_array($contextLayer['historical_spot_reference'] ?? null) ? $contextLayer['historical_spot_reference'] : [];

        if (($series['average_total'] ?? null) !== null) {
            $points[] = sprintf(
                'Series total context: last %d matchup totals average %.1f vs market %s, direction %s.',
                (int) ($series['sample_size'] ?? 0),
                (float) $series['average_total'],
                $series['market_total'] !== null ? $this->number((float) $series['market_total']) : 'N/A',
                (string) ($series['direction'] ?? 'unknown')
            );
        }

        if (($otAdjusted['average'] ?? null) !== null || ($nonOt['average'] ?? null) !== null) {
            $points[] = sprintf(
                'Total cleanup: overtime-adjusted average %s; non-OT series average %s.',
                $otAdjusted['average'] !== null ? $this->number((float) $otAdjusted['average']) : 'N/A',
                $nonOt['average'] !== null ? $this->number((float) $nonOt['average']) : 'N/A'
            );
        }

        if ((int) ($spikes['count'] ?? 0) > 0) {
            $points[] = sprintf(
                'Quarter scoring spikes: %d quarters cleared %d combined points; max quarter total %s.',
                (int) $spikes['count'],
                (int) ($spikes['threshold'] ?? 65),
                $spikes['max_quarter_total'] !== null ? $this->number((float) $spikes['max_quarter_total']) : 'N/A'
            );
        }

        if (($fouls['risk'] ?? 'low') !== 'low') {
            $points[] = sprintf(
                'Playoff late-game risk: %s from %d close games, %d OT games, and %d fourth-quarter-plus fouls.',
                (string) $fouls['risk'],
                (int) ($fouls['close_games'] ?? 0),
                (int) ($fouls['overtime_games'] ?? 0),
                (int) ($fouls['fourth_quarter_plus_fouls'] ?? 0)
            );
        }

        if (($injuries['level'] ?? 'none') !== 'none') {
            $points[] = sprintf(
                'Injury importance layer: %s impact, weighted absences home %.2f / away %.2f.',
                (string) $injuries['level'],
                (float) ($injuries['home_weighted_absences'] ?? 0.0),
                (float) ($injuries['away_weighted_absences'] ?? 0.0)
            );
        }

        if (($market['snapshot_count'] ?? 0) > 0) {
            $points[] = sprintf(
                'Market movement: total move %s, home spread move %s across %d snapshots.',
                $market['total_move'] !== null ? $this->signedNumber((float) $market['total_move']) : 'N/A',
                $market['home_spread_move'] !== null ? $this->signedNumber((float) $market['home_spread_move']) : 'N/A',
                (int) $market['snapshot_count']
            );
        }

        if (($conflict['has_conflict'] ?? false) === true) {
            $points[] = sprintf(
                'Model-vs-series conflict: model total direction %s, series context %s, bet direction %s.',
                (string) ($conflict['model_total_direction'] ?? 'unknown'),
                (string) ($conflict['series_direction'] ?? 'unknown'),
                (string) ($conflict['bet_direction'] ?? 'unknown')
            );
        }

        if (($historical['available'] ?? false) === true) {
            $points[] = sprintf(
                'Historical spot reference: %d similar spots, bet hit rate %s, winner accuracy %s, avg total error %s.',
                (int) ($historical['sample_size'] ?? 0),
                $historical['hit_rate'] !== null ? $this->number((float) $historical['hit_rate']).'%' : 'N/A',
                $historical['winner_accuracy'] !== null ? $this->number((float) $historical['winner_accuracy']).'%' : 'N/A',
                $historical['avg_total_error'] !== null ? $this->number((float) $historical['avg_total_error']) : 'N/A'
            );
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $contextLayer
     * @return array<int, string>
     */
    private function contextLayerPromptLines(array $contextLayer): array
    {
        return array_map(
            fn (string $point): string => 'Context layer: '.$point,
            $this->contextLayerKeyPoints($contextLayer)
        );
    }

    /**
     * @param  array<string, mixed>  $contextLayer
     */
    private function contextConflictSummary(array $contextLayer): string
    {
        $series = data_get($contextLayer, 'series_total_trend.average_total');
        $otAdjusted = data_get($contextLayer, 'overtime_adjusted_total.average');
        $nonOt = data_get($contextLayer, 'non_ot_series_average.average');
        $spikes = (int) data_get($contextLayer, 'quarter_scoring_spikes.count', 0);

        return sprintf(
            'Series avg %s, OT-adjusted %s, non-OT avg %s, quarter spikes %d.',
            is_numeric($series) ? $this->number((float) $series) : 'N/A',
            is_numeric($otAdjusted) ? $this->number((float) $otAdjusted) : 'N/A',
            is_numeric($nonOt) ? $this->number((float) $nonOt) : 'N/A',
            $spikes
        );
    }

    /**
     * @return array{bet_pick:string,reasoning:string}
     */
    private function buildBettingPlan(
        ?array $bestBet,
        string $pickedTeam,
        float $spread,
        float $confidence,
        ?float $marketEdge,
        ?float $vegasSpread,
        string $homeTeam,
        string $awayTeam,
        int $homeRest,
        int $awayRest,
        ?string $trendLeader = null,
        ?float $trendGap = null,
        ?string $efficiencyLeader = null,
        ?float $efficiencyGap = null,
        array $contextLayer = []
    ): array {
        $restContext = $this->restContextSentence($homeTeam, $homeRest, $awayTeam, $awayRest);
        $whyContext = $this->buildWhyContext(
            pickedTeam: $pickedTeam,
            confidence: $confidence,
            marketEdge: $marketEdge,
            homeTeam: $homeTeam,
            awayTeam: $awayTeam,
            trendLeader: $trendLeader,
            trendGap: $trendGap,
            efficiencyLeader: $efficiencyLeader,
            efficiencyGap: $efficiencyGap,
            restContext: $restContext
        );
        $bettingContext = is_array($contextLayer['betting_context'] ?? null) ? $contextLayer['betting_context'] : [];
        $classification = (string) ($bettingContext['classification'] ?? '');
        $againstBet = array_values(array_filter(array_map('strval', (array) ($bettingContext['against_bet'] ?? []))));
        $forBet = array_values(array_filter(array_map('strval', (array) ($bettingContext['for_bet'] ?? []))));
        $passReasons = array_values(array_filter(array_map('strval', (array) ($bettingContext['pass_reasons'] ?? []))));

        if ($bestBet && in_array($classification, ['pass_or_wait', 'clear_pass'], true)) {
            $forText = $forBet !== [] ? 'For the bet: '.implode(' ', $forBet) : 'For the bet: the model has a qualified edge.';
            $againstText = $againstBet !== [] ? 'Against the bet: '.implode(' ', $againstBet) : 'Against the bet: context does not confirm the edge.';

            return [
                'bet_pick' => $classification === 'clear_pass'
                    ? 'Clear pass.'
                    : 'Pass pregame / wait for a live entry.',
                'reasoning' => trim($whyContext.' '.$forText.' '.$againstText),
                'classification' => $classification,
                'for_bet' => $forBet,
                'against_bet' => $againstBet,
                'pass_reasons' => $passReasons,
                'reason_codes' => array_values(array_filter(array_map('strval', (array) ($contextLayer['reason_codes'] ?? [])))),
            ];
        }

        if ($bestBet) {
            $betText = $this->betPickLabel($bestBet, $pickedTeam, $homeTeam, $awayTeam);
            $bestBetReasoning = $this->ensureSentenceEnding((string) ($bestBet['reasoning'] ?? ''));
            $contextRiskText = $againstBet !== []
                ? 'Context risk: '.implode(' ', $againstBet)
                : '';

            return [
                'bet_pick' => $betText.'.',
                'reasoning' => trim($bestBetReasoning.' '.$whyContext.' '.$contextRiskText),
                'classification' => $classification !== '' ? $classification : 'playable',
                'for_bet' => $forBet,
                'against_bet' => $againstBet,
                'pass_reasons' => $passReasons,
                'reason_codes' => array_values(array_filter(array_map('strval', (array) ($contextLayer['reason_codes'] ?? [])))),
            ];
        }

        if ($vegasSpread === null) {
            return [
                'bet_pick' => 'No spread bet until a current market line is available.',
                'reasoning' => sprintf(
                    '%s Treat %s as a model lean, not a bet recommendation, because there is no Vegas spread to compare against.',
                    $whyContext,
                    $pickedTeam
                ),
            ];
        }

        if ($vegasSpread !== null && $marketEdge !== null) {
            $edgeTeam = $marketEdge >= 0 ? $homeTeam : $awayTeam;
            $betPick = sprintf(
                'Bet %s to cover',
                $edgeTeam
            );
            $reasoning = $whyContext;
        } else {
            $betPick = sprintf(
                'Bet %s to cover',
                $pickedTeam
            );
            $reasoning = $whyContext;
        }

        return [
            'bet_pick' => $betPick.'.',
            'reasoning' => $reasoning,
        ];
    }

    private function buildWhyContext(
        string $pickedTeam,
        float $confidence,
        ?float $marketEdge,
        string $homeTeam,
        string $awayTeam,
        ?string $trendLeader,
        ?float $trendGap,
        ?string $efficiencyLeader,
        ?float $efficiencyGap,
        string $restContext
    ): string {
        $parts = [sprintf('Model confidence is %.1f for %s', $confidence, $pickedTeam)];

        if ($marketEdge !== null) {
            $edgeTeam = $marketEdge >= 0 ? $homeTeam : $awayTeam;
            $parts[] = sprintf('model-vs-market value leans %s', $edgeTeam);
        }

        if ($trendLeader !== null && $trendGap !== null && $trendGap >= 0.5) {
            $parts[] = sprintf('recent form leans %s', $trendLeader);
        }

        if ($efficiencyLeader !== null && $efficiencyGap !== null && $efficiencyGap >= 0.5) {
            $parts[] = sprintf('net-rating edge leans %s', $efficiencyLeader);
        }

        $parts[] = rtrim($restContext, '.');

        return ucfirst(implode('; ', $parts)).'.';
    }

    private function ensureSentenceEnding(string $value): string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/[.!?]$/', $value) === 1) {
            return $value;
        }

        return $value.'.';
    }

    private function buildSocialCaption(
        ?array $bestBet,
        string $pickedTeam,
        string $otherTeam,
        float $winProb,
        float $spread,
        float $total,
        ?string $trendSentence = null,
        ?float $vegasSpread = null,
        ?float $marketEdge = null,
        ?string $marketEdgeTeam = null
    ): string {
        if ($bestBet) {
            return sprintf(
                'Best bet: %s%s. %s',
                $bestBet['recommendation'],
                $bestBet['odds_text'] !== '' ? " ({$bestBet['odds_text']})" : '',
                $bestBet['reasoning']
            );
        }

        $base = sprintf(
            '%s is the lean vs %s (%s win probability). Line %s, total %s.',
            $pickedTeam,
            $otherTeam,
            $this->percent($winProb),
            $this->signedNumber($spread),
            $this->number($total)
        );

        if ($vegasSpread !== null && $marketEdge !== null && $marketEdgeTeam !== null) {
            $base = sprintf(
                '%s is the lean vs %s (%s win probability). Edge %.1f points to %s vs Vegas %s. Total %s.',
                $pickedTeam,
                $otherTeam,
                $this->percent($winProb),
                abs($marketEdge),
                $marketEdgeTeam,
                $this->signedNumber($vegasSpread),
                $this->number($total)
            );
        }

        if (! $trendSentence || trim($trendSentence) === '') {
            return $base.' Watch Q1 pace before adding live totals.';
        }

        return $base.' Trend angle: '.$this->cleanTrendSentence($trendSentence).'.';
    }

    private function betPickLabel(array $bestBet, string $pickedTeam, string $homeTeam, string $awayTeam): string
    {
        $type = strtolower((string) ($bestBet['type'] ?? ''));
        $recommendation = trim((string) ($bestBet['recommendation'] ?? ''));
        $teamFromRecommendation = $this->extractTeamFromRecommendation($recommendation, $homeTeam, $awayTeam);

        if ($type === 'moneyline') {
            return 'Bet '.($teamFromRecommendation ?? $pickedTeam).' moneyline';
        }

        if ($type === 'total') {
            $lower = strtolower($recommendation);
            if (str_contains($lower, 'under')) {
                return 'Bet the under';
            }
            if (str_contains($lower, 'over')) {
                return 'Bet the over';
            }

            return 'Bet the game total';
        }

        return 'Bet '.($teamFromRecommendation ?? $pickedTeam).' to cover';
    }

    private function extractTeamFromRecommendation(string $recommendation, string $homeTeam, string $awayTeam): ?string
    {
        if ($recommendation === '') {
            return null;
        }

        if (stripos($recommendation, $homeTeam) !== false) {
            return $homeTeam;
        }

        if (stripos($recommendation, $awayTeam) !== false) {
            return $awayTeam;
        }

        return null;
    }

    private function restContextSentence(string $homeTeam, int $homeRest, string $awayTeam, int $awayRest): string
    {
        if ($homeRest === $awayRest) {
            return "Rest is even ({$homeRest} days each).";
        }

        if (max($homeRest, $awayRest) >= 8) {
            $leader = $homeRest > $awayRest ? $homeTeam : $awayTeam;
            $longRest = max($homeRest, $awayRest);
            $shortRest = min($homeRest, $awayRest);

            return sprintf(
                'Schedule note: %s carry the longer layoff (%d vs %d days), which is useful rest but also a rhythm risk.',
                $leader,
                $longRest,
                $shortRest
            );
        }

        if ($homeRest > $awayRest) {
            return sprintf(
                'Rest edge: %s (%d vs %d days).',
                $homeTeam,
                $homeRest,
                $awayRest
            );
        }

        return sprintf(
            'Rest edge: %s (%d vs %d days).',
            $awayTeam,
            $awayRest,
            $homeRest
        );
    }

    private function spreadNarrativeLead(
        float $spread,
        ?float $vegasSpread,
        ?float $marketEdge,
        ?string $edgeTeam
    ): string {
        if ($vegasSpread !== null && $marketEdge !== null && $edgeTeam !== null) {
            return sprintf(
                'a %.1f-point edge to %s versus the Vegas line (%s)',
                abs($marketEdge),
                $edgeTeam,
                $this->signedNumber($vegasSpread)
            );
        }

        return 'a projected spread of '.$this->signedNumber($spread);
    }

    /**
     * @return array{recommendation:string,reasoning:string,odds_text:string,type:string}|null
     */
    private function resolveBestBet(?Game $game): ?array
    {
        if (! $game || ! $game->relationLoaded('prediction')) {
            $game?->loadMissing('prediction');
        }
        if (! $game || ! $game->prediction) {
            return null;
        }

        $recommendations = app(CalculateBettingValue::class)->execute($game);
        if (! is_array($recommendations) || $recommendations === []) {
            return null;
        }

        $priority = ['moneyline' => 0, 'total' => 1, 'spread' => 2];
        usort($recommendations, function (array $a, array $b) use ($priority): int {
            $pa = $priority[(string) ($a['type'] ?? '')] ?? 99;
            $pb = $priority[(string) ($b['type'] ?? '')] ?? 99;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return ((float) ($b['edge'] ?? 0)) <=> ((float) ($a['edge'] ?? 0));
        });

        $best = $recommendations[0] ?? null;
        if (! is_array($best)) {
            return null;
        }

        $recommendation = trim((string) ($best['recommendation'] ?? ''));
        $reasoning = trim((string) ($best['reasoning'] ?? ''));
        $odds = $best['odds'] ?? null;

        if ($recommendation === '') {
            return null;
        }

        return [
            'type' => (string) ($best['type'] ?? ''),
            'recommendation' => $recommendation,
            'reasoning' => $reasoning !== '' ? $reasoning : 'Model edge vs market price is positive.',
            'odds_text' => $this->formatOddsText($odds),
            'edge' => is_numeric($best['edge'] ?? null) ? (float) $best['edge'] : null,
        ];
    }

    private function buildProfileSentence(
        string $pickedTeam,
        string $efficiencyLeader,
        float $efficiencyGap,
        string $homeTeam,
        float $homeNet,
        string $awayTeam,
        float $awayNet
    ): string {
        if ($efficiencyGap <= 0.6) {
            return sprintf(
                'Efficiency is basically even (%s %.1f net vs %s %.1f net), so non-efficiency drivers matter more.',
                $homeTeam,
                $homeNet,
                $awayTeam,
                $awayNet
            );
        }

        if ($efficiencyLeader === $pickedTeam) {
            return sprintf('Efficiency profile also supports %s by %.1f net-rating points.', $pickedTeam, $efficiencyGap);
        }

        return sprintf(
            'Raw efficiency leans %s by %.1f net-rating points, but the composite model still favors %s based on broader weighting.',
            $efficiencyLeader,
            $efficiencyGap,
            $pickedTeam
        );
    }

    /**
     * @param  array<string, array<int, string>>  $trends
     * @return array{
     *   categories: array<string, array<int, string>>,
     *   category_count: int,
     *   message_count: int
     * }
     */
    private function normalizeTrendDataset(array $trends): array
    {
        $normalized = [];
        $messageCount = 0;

        foreach ($trends as $category => $messages) {
            if (! is_array($messages)) {
                continue;
            }
            $cleaned = array_values(array_filter(
                array_map(fn ($message) => $this->cleanTrendSentence((string) $message), $messages),
                fn ($message) => $message !== ''
            ));
            if ($cleaned === []) {
                continue;
            }
            $normalized[(string) $category] = $cleaned;
            $messageCount += count($cleaned);
        }

        return [
            'categories' => $normalized,
            'category_count' => count($normalized),
            'message_count' => $messageCount,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $trendCategories
     * @return array<int, array{category:string,message:string,score:float,abs_score:float}>
     */
    private function scoreTrendSignals(array $trendCategories): array
    {
        $signals = [];

        foreach ($trendCategories as $category => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                $cleanMessage = $this->cleanTrendSentence((string) $message);
                if ($cleanMessage === '') {
                    continue;
                }

                $sentiment = $this->trendSentiment($cleanMessage);
                $strength = $this->trendStrength($cleanMessage);
                $score = $sentiment * $strength;

                $signals[] = [
                    'category' => (string) $category,
                    'message' => $cleanMessage,
                    'score' => $score,
                    'abs_score' => abs($score),
                ];
            }
        }

        usort($signals, fn (array $a, array $b): int => $b['abs_score'] <=> $a['abs_score']);

        return $signals;
    }

    /**
     * @param  array<int, array{category:string,message:string,score:float,abs_score:float}>  $signals
     * @return array{category:string,message:string,score:float,abs_score:float}|null
     */
    private function bestTrendSignal(array $signals, bool $positive): ?array
    {
        $best = null;

        foreach ($signals as $signal) {
            if ($positive && $signal['score'] <= 0) {
                continue;
            }
            if (! $positive && $signal['score'] >= 0) {
                continue;
            }

            if ($best === null || $signal['abs_score'] > $best['abs_score']) {
                $best = $signal;
            }
        }

        return $best;
    }

    private function trendSentiment(string $message): int
    {
        $message = strtolower($message);

        $positiveKeywords = [
            'outscored',
            'have won',
            'win',
            'winning',
            'trending up',
            'clutch',
            'higher',
            'better',
            'favors',
            'cover',
            'leads',
        ];

        $negativeKeywords = [
            'struggle',
            'only',
            'trending down',
            'have lost',
            'losing',
            'worse',
            'allowing',
            'decline',
            'underperform',
        ];

        $positive = 0;
        foreach ($positiveKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                $positive++;
            }
        }

        $negative = 0;
        foreach ($negativeKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                $negative++;
            }
        }

        if ($positive > $negative) {
            return 1;
        }
        if ($negative > $positive) {
            return -1;
        }

        return 0;
    }

    private function trendStrength(string $message): float
    {
        $strength = 25.0;

        if (preg_match_all('/(\d+(?:\.\d+)?)%/', $message, $percentMatches)) {
            $percentages = array_map('floatval', $percentMatches[1]);
            $strength = max($strength, max($percentages));
        }

        if (preg_match('/\((\d+)\/(\d+)\)/', $message, $fractionMatches)) {
            $wins = (float) $fractionMatches[1];
            $total = max(1.0, (float) $fractionMatches[2]);
            $strength = max($strength, ($wins / $total) * 100);
        }

        if (preg_match('/in\s+(\d+)\s+of\s+(?:their\s+)?last\s+(\d+)/i', $message, $lastMatches)) {
            $wins = (float) $lastMatches[1];
            $total = max(1.0, (float) $lastMatches[2]);
            $strength = max($strength, ($wins / $total) * 100);
        }

        return round($strength, 2);
    }

    private function cleanTrendSentence(string $text): string
    {
        $clean = trim($text);

        return rtrim($clean, " .\t\n\r\0\x0B");
    }

    private function teamName(mixed $team, string $fallback): string
    {
        if (! $team) {
            return $fallback;
        }

        $location = (string) ($team->location ?? '');
        $name = (string) ($team->name ?? '');
        $display = trim("{$location} {$name}");
        if ($display !== '') {
            return $display;
        }

        $school = (string) ($team->school ?? '');
        $mascot = (string) ($team->mascot ?? '');
        $display = trim("{$school} {$mascot}");

        return $display !== '' ? $display : ((string) ($team->abbreviation ?? $fallback));
    }

    private function percent(float $value): string
    {
        return number_format($value * 100, 1).'%';
    }

    private function number(float $value): string
    {
        return number_format($value, 1);
    }

    private function signedNumber(float $value): string
    {
        return sprintf('%+.1f', $value);
    }

    private function formatOddsText(mixed $odds): string
    {
        if (is_int($odds) || is_float($odds)) {
            $numeric = (int) round((float) $odds);

            return $numeric > 0 ? '+'.$numeric : (string) $numeric;
        }

        if (is_string($odds)) {
            $trimmed = trim($odds);
            if ($trimmed === '') {
                return '';
            }
            if ($trimmed[0] === '+' || $trimmed[0] === '-') {
                return $trimmed;
            }
            if (is_numeric($trimmed)) {
                $numeric = (int) round((float) $trimmed);

                return $numeric > 0 ? '+'.$numeric : (string) $numeric;
            }

            return $trimmed;
        }

        return '';
    }
}
