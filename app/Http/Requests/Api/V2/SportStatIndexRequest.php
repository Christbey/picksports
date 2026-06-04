<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportStatIndexRequest extends FormRequest
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
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'team_id' => ['sometimes', 'integer', 'min:1'],
            'player_id' => ['sometimes', 'integer', 'min:1'],
            'stat_type' => ['sometimes', 'string', 'max:50'],
            'team_type' => ['sometimes', 'string', 'max:50'],
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
            'game_id',
            'team_id',
            'player_id',
            'stat_type',
            'team_type',
            'per_page',
        ]);

        foreach (['season', 'week', 'game_id', 'team_id', 'player_id', 'per_page'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        foreach (['season_type', 'from_date', 'to_date', 'stat_type', 'team_type'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = trim((string) $filters[$key]);
            }
        }

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = min((int) $filters['per_page'], self::MAX_PER_PAGE);
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== '');
    }
}
