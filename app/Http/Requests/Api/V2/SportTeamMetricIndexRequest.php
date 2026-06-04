<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportTeamMetricIndexRequest extends FormRequest
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
            'team_id' => ['sometimes', 'integer', 'min:1'],
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
            'team_id',
            'per_page',
        ]);

        foreach (['season', 'team_id', 'per_page'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        if (array_key_exists('season_type', $filters)) {
            $filters['season_type'] = trim((string) $filters['season_type']);
        }

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = min((int) $filters['per_page'], self::MAX_PER_PAGE);
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== '');
    }
}
