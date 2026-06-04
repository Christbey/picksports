<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportPlayerPropIndexRequest extends FormRequest
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
            'date' => ['sometimes', 'date'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => array_filter([
                'sometimes',
                'date',
                $this->filled('from_date') ? 'after_or_equal:from_date' : null,
            ]),
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'player_id' => ['sometimes', 'integer', 'min:1'],
            'market' => ['sometimes', 'string', 'max:100'],
            'bookmaker' => ['sometimes', 'string', 'max:100'],
            'recommended_side' => ['sometimes', 'string', 'max:20'],
            'only_ungraded' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only([
            'date',
            'from_date',
            'to_date',
            'game_id',
            'player_id',
            'market',
            'bookmaker',
            'recommended_side',
            'only_ungraded',
            'per_page',
        ]);

        foreach (['game_id', 'player_id', 'per_page'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        foreach (['date', 'from_date', 'to_date', 'market', 'bookmaker', 'recommended_side'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = trim((string) $filters[$key]);
            }
        }

        if (array_key_exists('only_ungraded', $filters)) {
            $filters['only_ungraded'] = (bool) $filters['only_ungraded'];
        }

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = min((int) $filters['per_page'], self::MAX_PER_PAGE);
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== '');
    }
}
