<?php

namespace App\Http\Resources;

use App\Services\Predictions\LivePredictionStore;
use App\Support\NflValidatedSignalCombos;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardPredictionResource extends JsonResource
{
    private array $preparedLiveFields = LivePredictionStore::EMPTY_FIELDS;

    public function liveFields(array $fields): self
    {
        $this->preparedLiveFields = $fields;

        return $this;
    }

    protected string $sport = '';

    protected array $liveStatuses = [];

    protected array $finalStatuses = [];

    protected ?string $liveRemainingField = 'live_seconds_remaining';

    protected bool $includeInning = false;

    protected bool $includeBettingValue = false;

    protected mixed $bettingValue = null;

    protected ?string $bettingValueDebug = null;

    protected bool $includeRecommendation = false;

    protected mixed $recommendation = null;

    public function sport(string $sport): self
    {
        $this->sport = $sport;

        return $this;
    }

    public function statuses(array $liveStatuses, array $finalStatuses): self
    {
        $this->liveStatuses = $liveStatuses;
        $this->finalStatuses = $finalStatuses;

        return $this;
    }

    public function liveRemainingField(?string $field): self
    {
        $this->liveRemainingField = $field;

        return $this;
    }

    public function includeInning(bool $include = true): self
    {
        $this->includeInning = $include;

        return $this;
    }

    public function bettingValue(mixed $bettingValue): self
    {
        $this->includeBettingValue = true;
        $this->bettingValue = $bettingValue;

        return $this;
    }

    public function bettingValueDebug(?string $reason): self
    {
        $this->bettingValueDebug = $reason;

        return $this;
    }

    public function recommendation(mixed $recommendation): self
    {
        $this->includeRecommendation = true;
        $this->recommendation = $recommendation;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $prediction = $this->resource;
        $game = $prediction->game;
        $live = $this->preparedLiveFields;

        $isLive = in_array($game->status, $this->liveStatuses, true);
        $isFinal = in_array($game->status, $this->finalStatuses, true);

        $data = [
            'id' => $prediction->id,
            'sport' => $this->sport,
            'spread_convention' => 'home_margin',
            'game_id' => $game->id,
            'season_type' => $game->season_type,
            'game' => $game->name,
            'game_time' => $this->gameDateTime($game),
            'home_team' => $game->homeTeam?->abbreviation,
            'away_team' => $game->awayTeam?->abbreviation,
            'win_probability' => $prediction->win_probability === null ? null : (float) $prediction->win_probability,
            'predicted_spread' => $prediction->predicted_spread === null ? null : (float) $prediction->predicted_spread,
            'predicted_total' => $prediction->predicted_total === null ? null : (float) $prediction->predicted_total,
            'actual_total' => $prediction->actual_total !== null ? (float) $prediction->actual_total : null,
            'winner_correct' => $prediction->winner_correct,
            'home_logo' => $game->homeTeam?->logo_url,
            'away_logo' => $game->awayTeam?->logo_url,
            'home_color' => $game->homeTeam?->color,
            'away_color' => $game->awayTeam?->color,
            'is_live' => $isLive,
            'is_final' => $isFinal,
            'home_score' => ($isLive || $isFinal) ? $game->home_score : null,
            'away_score' => ($isLive || $isFinal) ? $game->away_score : null,
            'status' => $game->status,
            'live_win_probability' => $isLive && $live['live_win_probability'] !== null ? (float) $live['live_win_probability'] : null,
            'live_predicted_spread' => $isLive && $live['live_predicted_spread'] !== null ? (float) $live['live_predicted_spread'] : null,
            'live_predicted_total' => $isLive && $live['live_predicted_total'] !== null ? (float) $live['live_predicted_total'] : null,
        ];

        if ($this->includeInning) {
            $data['inning'] = $isLive ? $game->inning : null;
            $data['inning_state'] = $isLive ? $game->inning_state : null;
            $data['balls'] = $isLive ? $game->balls : null;
            $data['strikes'] = $isLive ? $game->strikes : null;
            $data['outs'] = $isLive ? $game->outs : null;
        } else {
            $data['period'] = $isLive ? $game->period : null;
            $data['game_clock'] = $isLive ? $game->game_clock : null;
        }

        if ($this->liveRemainingField) {
            $data[$this->liveRemainingField] = $isLive ? $live[$this->liveRemainingField] : null;
        }

        if ($this->includeBettingValue) {
            $data['betting_value'] = $this->bettingValue;
            $data['betting_value_debug'] = $this->bettingValueDebug;
        }

        if ($this->includeRecommendation) {
            $data['recommendation'] = $this->recommendation;
            $data['public_recommendation'] = data_get($this->recommendation, 'public');
            $data['candidate_recommendation'] = data_get($this->recommendation, 'candidate');
        }

        if (strtolower($this->sport) === 'nfl') {
            $data['prediction_analysis'] = $this->predictionAnalysisSummary();
        }

        return $data;
    }

    private function gameDateTime(object $game): ?string
    {
        return $game instanceof Model
            ? GameDateTimePresenter::startsAtForGame(strtolower($this->sport), $game)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function predictionAnalysisSummary(): ?array
    {
        $metadata = is_array($this->resource->model_metadata ?? null) ? $this->resource->model_metadata : [];
        $analysis = $metadata['analysis_layer'] ?? null;

        if (! is_array($analysis) || ($analysis['applied'] ?? false) !== true) {
            return null;
        }

        $analysis = NflValidatedSignalCombos::publicAnalysis($analysis);

        return [
            'trust_score' => isset($analysis['trust_score']) ? (float) $analysis['trust_score'] : null,
            'bet_classification' => $analysis['bet_classification'] ?? null,
            'model_signal_classification' => $analysis['model_signal_classification'] ?? null,
            'risk_flags' => array_values((array) ($analysis['risk_flags'] ?? [])),
            'reason_codes' => array_values((array) ($analysis['reason_codes'] ?? [])),
            'reason_code_metadata' => $analysis['reason_code_metadata'] ?? [],
            'bet_rule_evaluation' => $analysis['bet_rule_evaluation'] ?? null,
            'validated_signals' => $analysis['validated_signals'] ?? [],
            'best_validated_signal' => $analysis['best_validated_signal'] ?? null,
            'calculated_edge' => $analysis['calculated_edge'] ?? null,
            'analysis_confidence' => $analysis['analysis_confidence'] ?? null,
        ];
    }
}
