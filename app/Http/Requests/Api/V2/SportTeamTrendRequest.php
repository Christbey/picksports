<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportTeamTrendRequest extends FormRequest
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
            'games' => ['sometimes', 'string', 'max:20'],
            'season' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'season_type' => ['sometimes', 'string', 'max:100'],
            'before_date' => ['sometimes', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only([
            'games',
            'season',
            'season_type',
            'before_date',
        ]);

        if (array_key_exists('games', $filters)) {
            $filters['games'] = strtolower(trim((string) $filters['games']));
        }

        if (array_key_exists('season', $filters)) {
            $filters['season'] = (int) $filters['season'];
        }

        if (array_key_exists('season_type', $filters)) {
            $filters['season_type'] = trim((string) $filters['season_type']);
        }

        if (array_key_exists('before_date', $filters)) {
            $filters['before_date'] = (string) $filters['before_date'];
        }

        return array_filter(
            $filters,
            fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
