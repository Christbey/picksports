<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportPredictionIndexRequest extends FormRequest
{
    private const MAX_PER_PAGE = 100;

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
            'week' => ['sometimes', 'integer', 'min:0'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => array_filter([
                'sometimes',
                'date',
                $this->filled('from_date') ? 'after_or_equal:from_date' : null,
            ]),
            'status' => ['sometimes', 'string', 'max:100'],
            'team_id' => ['sometimes', 'integer', 'min:1'],
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'has_value' => ['sometimes', 'boolean'],
            'market' => ['sometimes', 'string', 'max:50'],
            'include' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
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
            'week',
            'from_date',
            'to_date',
            'status',
            'team_id',
            'game_id',
            'has_value',
            'market',
            'include',
            'per_page',
        ]);

        foreach (['season', 'week', 'team_id', 'game_id', 'per_page'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        foreach (['season_type', 'from_date', 'to_date', 'status', 'market', 'include'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = trim((string) $filters[$key]);
            }
        }

        if (array_key_exists('has_value', $filters)) {
            $filters['has_value'] = (bool) $filters['has_value'];
        }

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = min((int) $filters['per_page'], self::MAX_PER_PAGE);
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== '');
    }
}
