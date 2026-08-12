<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportPlayerLeaderboardIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'season' => ['sometimes', 'integer'],
            'season_type' => ['sometimes', 'string', 'max:50'],
            'stat_type' => ['sometimes', 'string', 'max:50'],
            'min_games' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'focus_player_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only([
            'season',
            'season_type',
            'stat_type',
            'min_games',
            'focus_player_id',
        ]);

        foreach (['season', 'min_games', 'focus_player_id'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        foreach (['season_type', 'stat_type'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = trim((string) $filters[$key]);
            }
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== '');
    }
}
