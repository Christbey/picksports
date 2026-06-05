<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportForecastIndexRequest extends FormRequest
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
            'season' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'as_of_date' => ['sometimes', 'date'],
            'require_historical_metrics' => ['sometimes', 'boolean'],
            'sort_by' => ['sometimes', 'string', 'max:100'],
            'sort_direction' => ['sometimes', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only([
            'season',
            'as_of_date',
            'require_historical_metrics',
            'sort_by',
            'sort_direction',
        ]);

        if (array_key_exists('season', $filters)) {
            $filters['season'] = (int) $filters['season'];
        }

        if (array_key_exists('require_historical_metrics', $filters)) {
            $filters['require_historical_metrics'] = filter_var(
                $filters['require_historical_metrics'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (array_key_exists('sort_direction', $filters)) {
            $filters['sort_direction'] = strtolower((string) $filters['sort_direction']) === 'asc' ? 'asc' : 'desc';
        }

        return array_filter(
            $filters,
            fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
