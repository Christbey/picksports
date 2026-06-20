<?php

namespace App\Http\Resources\Api\V2;

use App\Models\MLB\PickCandidate;
use App\Services\MLB\Picks\MlbPickExplanationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MlbPickCandidateResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly MlbPickExplanationService $explanations,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PickCandidate $candidate */
        $candidate = $this->resource;

        return [
            'id' => $candidate->id,
            'season' => $candidate->season,
            'game_id' => $candidate->game_id,
            'prediction_id' => $candidate->prediction_id,
            'market_type' => $candidate->market_type,
            'market_key' => $candidate->market_key,
            'label' => $this->label($candidate),
            'side' => $candidate->side,
            'team' => $candidate->team ? [
                'id' => $candidate->team->id,
                'abbreviation' => $candidate->team->abbreviation,
                'display_name' => $candidate->team->display_name,
            ] : null,
            'player' => $candidate->player ? [
                'id' => $candidate->player->id,
                'display_name' => $candidate->player->display_name ?? $candidate->player->full_name ?? $candidate->player->name,
            ] : null,
            'game' => $candidate->game ? [
                'id' => $candidate->game->id,
                'short_name' => $candidate->game->short_name,
                'game_date' => $candidate->game->game_date?->toISOString(),
                'status' => $candidate->game->status,
                'home_team' => $candidate->game->homeTeam?->abbreviation,
                'away_team' => $candidate->game->awayTeam?->abbreviation,
            ] : null,
            'line' => $candidate->line !== null ? (float) $candidate->line : null,
            'price' => $candidate->price,
            'book' => $candidate->book,
            'score' => $candidate->score,
            'confidence' => $candidate->confidence !== null ? (float) $candidate->confidence : null,
            'model_probability' => $candidate->model_probability !== null ? (float) $candidate->model_probability : null,
            'market_probability' => $candidate->market_probability !== null ? (float) $candidate->market_probability : null,
            'no_vig_probability' => $candidate->no_vig_probability !== null ? (float) $candidate->no_vig_probability : null,
            'blend_probability' => $candidate->blend_probability !== null ? (float) $candidate->blend_probability : null,
            'edge_raw' => $candidate->edge_raw !== null ? (float) $candidate->edge_raw : null,
            'edge_no_vig' => $candidate->edge_no_vig !== null ? (float) $candidate->edge_no_vig : null,
            'projected_value' => $candidate->projected_value !== null ? (float) $candidate->projected_value : null,
            'status' => $candidate->status,
            'recommendation_label' => $candidate->recommendation_label,
            'internal_candidate_label' => data_get($candidate->feature_snapshot, 'internal_candidate_label'),
            'is_public' => (bool) $candidate->is_public,
            'is_tracking_only' => (bool) $candidate->is_tracking_only,
            'is_bet' => (bool) $candidate->is_bet,
            'reason_codes' => $candidate->reason_codes ?? [],
            'risk_flags' => $candidate->risk_flags ?? [],
            'feature_snapshot' => $candidate->feature_snapshot ?? [],
            'market_snapshot' => $candidate->market_snapshot ?? [],
            'explanation' => $this->explanations->explain($candidate),
            'generated_at' => $candidate->generated_at?->toISOString(),
            'graded_at' => $candidate->graded_at?->toISOString(),
            'result_status' => $candidate->result_status,
            'result_profit_units' => $candidate->result_profit_units !== null ? (float) $candidate->result_profit_units : null,
        ];
    }

    private function label(PickCandidate $candidate): string
    {
        $side = $candidate->team?->abbreviation ?: strtoupper($candidate->side);
        if ($candidate->market_type === 'player_prop') {
            $name = (string) data_get($candidate->feature_snapshot, 'player_name', 'Player');

            return trim($name.' '.ucfirst($candidate->side).' '.((string) $candidate->line).' '.str_replace('_', ' ', $candidate->market_key));
        }

        return trim($side.' '.($candidate->line !== null ? (string) $candidate->line : ''));
    }
}
