<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardPredictionResource extends JsonResource
{
    protected string $sport = '';

    protected array $liveStatuses = [];

    protected array $finalStatuses = [];

    protected ?string $liveRemainingField = 'live_seconds_remaining';

    protected bool $includeInning = false;

    protected bool $includeBettingValue = false;

    protected mixed $bettingValue = null;

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

    public function toArray(Request $request): array
    {
        $prediction = $this->resource;
        $game = $prediction->game;

        $isLive = in_array($game->status, $this->liveStatuses, true);
        $isFinal = in_array($game->status, $this->finalStatuses, true);

        $data = [
            'sport' => $this->sport,
            'game_id' => $game->id,
            'game' => $game->name,
            'game_time' => $game->game_date,
            'home_team' => $game->homeTeam?->abbreviation,
            'away_team' => $game->awayTeam?->abbreviation,
            'win_probability' => (float) $prediction->win_probability,
            'predicted_spread' => (float) $prediction->predicted_spread,
            'predicted_total' => (float) $prediction->predicted_total,
            'home_logo' => $game->homeTeam?->logo_url,
            'away_logo' => $game->awayTeam?->logo_url,
            'is_live' => $isLive,
            'is_final' => $isFinal,
            'home_score' => ($isLive || $isFinal) ? $game->home_score : null,
            'away_score' => ($isLive || $isFinal) ? $game->away_score : null,
            'status' => $game->status,
            'live_win_probability' => $isLive && $prediction->live_win_probability !== null ? (float) $prediction->live_win_probability : null,
            'live_predicted_spread' => $isLive && $prediction->live_predicted_spread !== null ? (float) $prediction->live_predicted_spread : null,
            'live_predicted_total' => $isLive && $prediction->live_predicted_total !== null ? (float) $prediction->live_predicted_total : null,
        ];

        if ($this->includeInning) {
            $data['inning'] = $isLive ? $game->inning : null;
            $data['inning_state'] = $isLive ? $game->inning_state : null;
        } else {
            $data['period'] = $isLive ? $game->period : null;
            $data['game_clock'] = $isLive ? $game->game_clock : null;
        }

        if ($this->liveRemainingField) {
            $data[$this->liveRemainingField] = $isLive ? $prediction->{$this->liveRemainingField} : null;
        }

        if ($this->includeBettingValue) {
            $data['betting_value'] = $this->bettingValue;
        }

        return $data;
    }
}
